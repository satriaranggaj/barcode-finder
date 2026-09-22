<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DuplicateProtectionTest extends TestCase
{
    use RefreshDatabase;

    private int $counter = 0;

    private function stage(User $user, string $photoHash, string $dhash): string
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('local');
        $this->counter++;
        $image = UploadedFile::fake()->image("q{$this->counter}.jpg", 300, 300);

        return app(SearchEvidence::class)->stage([
            'results' => [['rank' => 1, 'sku' => 'SKU-A', 'score' => 0.8]],
            'confidence' => 'high',
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => $photoHash, 'dhash' => $dhash, 'blur' => 120, 'min_side' => 300,
                'crop_pixels' => 90000, 'gray_std' => 45,
            ],
        ], $user->id);
    }

    public function test_reencoded_same_image_is_rejected_as_exact_duplicate(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU-A']);
        // Same decoded pixels in a different container share the content hash.
        $token = $this->stage($user, str_repeat('a', 64), str_repeat('0', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])->assertRedirect();
        $retry = $this->stage($user, str_repeat('a', 64), str_repeat('f', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $retry, 'sku' => 'SKU-A'])
            ->assertRedirect(route('products.show', $product))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SearchFeedback::count());
    }

    public function test_exact_duplicate_cleans_evidence_and_never_405s(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU-A']);
        $token = $this->stage($user, str_repeat('a', 64), str_repeat('0', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])->assertRedirect();

        $retry = $this->stage($user, str_repeat('a', 64), str_repeat('f', 16));
        $response = $this->actingAs($user)->post(route('search.feedback'), ['token' => $retry, 'sku' => 'SKU-A']);

        // No new feedback row is stored.
        $response->assertRedirect(route('products.show', $product));
        $this->assertSame(1, SearchFeedback::count());
        // Redirect target is the safe GET product page, never POST-only /search.
        $this->assertStringNotContainsString('/search', $response->headers->get('Location'));
        // Staged evidence is cleaned up like the normal flow.
        $this->assertSame([], Storage::disk('local')->allFiles('search-pending'));
        // Informational flash, not a red validation error.
        $response->assertSessionHas('success');
        $response->assertSessionHasNoErrors();
        // Following the redirect lands on 200, never 405.
        $this->actingAs($user)->get($response->headers->get('Location'))->assertOk();
    }

    public function test_non_duplicate_confirmation_still_works(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU-A']);
        $token = $this->stage($user, str_repeat('b', 64), str_repeat('1', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])
            ->assertRedirect(route('products.show', $product));
        $this->assertSame(1, SearchFeedback::count());
        $this->assertTrue(SearchFeedback::sole()->reference_eligible);
    }

    public function test_slightly_changed_image_is_stored_but_ineligible_with_reason(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU-A']);
        $token = $this->stage($user, str_repeat('a', 64), str_repeat('0', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])->assertRedirect();
        // Two bits flipped: near duplicate, not exact.
        $retry = $this->stage($user, str_repeat('b', 64), '03'.str_repeat('0', 14));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $retry, 'sku' => 'SKU-A'])->assertRedirect();
        $this->assertSame(2, SearchFeedback::count());
        $second = SearchFeedback::orderBy('id', 'desc')->first();
        $this->assertFalse($second->reference_eligible);
        $this->assertContains('near_duplicate', $second->evidence['quality']['reasons']);
        // Nothing existing was deleted by the similarity match.
        $this->assertSame(2, SearchFeedback::count());
    }

    public function test_similar_photo_for_different_sku_is_not_blocked(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $one = Product::create(['sku' => 'SKU-A']);
        $two = Product::create(['sku' => 'SKU-B']);
        $token = $this->stage($user, str_repeat('a', 64), str_repeat('0', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])->assertRedirect();
        $retry = $this->stage($user, str_repeat('b', 64), '03'.str_repeat('0', 14));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $retry, 'sku' => 'SKU-B'])->assertRedirect();
        $row = SearchFeedback::where('confirmed_sku', 'SKU-B')->sole();
        $this->assertTrue($row->reference_eligible);
        $this->assertNotContains('near_duplicate', $row->evidence['quality']['reasons']);
    }

    public function test_genuinely_different_view_is_eligible(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU-A']);
        $token = $this->stage($user, str_repeat('a', 64), str_repeat('0', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SKU-A'])->assertRedirect();
        $retry = $this->stage($user, str_repeat('b', 64), str_repeat('f', 16));
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $retry, 'sku' => 'SKU-A'])->assertRedirect();
        $this->assertSame(2, SearchFeedback::where('reference_eligible', true)->count());
    }
}
