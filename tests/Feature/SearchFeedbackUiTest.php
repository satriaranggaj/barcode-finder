<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SearchFeedbackUiTest extends TestCase
{
    use RefreshDatabase;

    private function fakeSearch(array $results, array $ambiguity = []): void
    {
        $image = UploadedFile::fake()->image('staged.jpg', 300, 300);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response(array_merge([
            'success' => true, 'confidence' => 'medium', 'relevance_calibrated' => false,
            'results' => $results,
            'query' => ['reason' => 'manual_selection', 'models' => ['siglip', 'dino']],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
                'blur' => 100, 'min_side' => 300, 'crop_pixels' => 90000,
            ],
        ], $ambiguity))]);
    }

    private function searchPage(User $user): TestResponse
    {
        config(['retrieval.driver' => 'faiss', 'retrieval.feedback_enabled' => true]);
        Storage::fake('local');
        $top = Product::create(['sku' => 'TOP-1', 'description' => 'Obeng plus']);
        $top->photos()->create(['path' => 'products/top.jpg', 'thumbnail_path' => 'thumbs/top.jpg']);
        $alt = Product::create(['sku' => 'TOP-2', 'description' => 'Obeng minus']);
        $alt->photos()->create(['path' => 'products/alt.jpg', 'thumbnail_path' => 'thumbs/alt.jpg']);
        $this->fakeSearch([
            ['rank' => 1, 'sku' => 'TOP-1', 'image_id' => 'front', 'score' => 0.91],
            ['rank' => 2, 'sku' => 'TOP-2', 'image_id' => 'front', 'score' => 0.42],
        ]);

        return $this->actingAs($user)->post(route('products.search'), [
            'image' => UploadedFile::fake()->image('query.jpg'),
            'allow_feedback' => '1',
        ]);
    }

    private function tokenFrom(string $html): string
    {
        $this->assertSame(1, preg_match('/name="token" value="([0-9a-f-]{36})"/', $html, $matches));

        return $matches[1];
    }

    public function test_results_show_prediction_quick_confirm_and_correction(): void
    {
        $response = $this->searchPage(User::factory()->create(['role' => 'admin']));
        $response->assertOk()->assertViewHas('feedbackToken')->assertViewHas('predictedSku', 'TOP-1');
        $html = $response->getContent();
        $this->assertStringContainsString('Prediksi AI:', $html);
        $this->assertStringContainsString('TOP-1', $html);
        // One quick-confirm button per candidate plus the correction form.
        $this->assertSame(2, substr_count($html, '✓ Ini benar:'));
        $this->assertStringContainsString('Koreksi: SKU lain yang benar', $html);
        $this->assertStringContainsString('candidate-skus', $html);
        // Accidental double clicks are suppressed client-side.
        $this->assertStringContainsString('querySelector(\'button[type=submit]\').disabled = true;', $html);
    }

    public function test_quick_confirm_stores_verified_feedback_with_success_message(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $this->tokenFrom($this->searchPage($user)->getContent());
        $response = $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'TOP-1']);
        $response->assertRedirect(route('products.show', Product::where('sku', 'TOP-1')->sole()));
        $response->assertSessionHas('success');
        $row = SearchFeedback::sole();
        $this->assertSame('TOP-1', $row->predicted_sku);
        $this->assertSame('TOP-1', $row->confirmed_sku);
        $this->assertSame('verified', $row->training_status);
        $followUp = $this->actingAs($user)->get(route('products.show', $row->confirmed_product_id));
        $followUp->assertSee('Konfirmasi disimpan sebagai data terverifikasi.');
    }

    public function test_prediction_without_confirmation_stores_nothing(): void
    {
        // Prediksi AI bukan ground truth: pencarian + staging saja tidak
        // menghasilkan label apapun sampai staff menekan konfirmasi.
        $response = $this->searchPage(User::factory()->create(['role' => 'admin']));
        $response->assertOk()->assertViewHas('feedbackToken');
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_correction_stores_hard_negative_pair(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $this->tokenFrom($this->searchPage($user)->getContent());
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'TOP-2'])
            ->assertRedirect();
        $row = SearchFeedback::sole();
        $this->assertSame('TOP-1', $row->predicted_sku);
        $this->assertSame('TOP-2', $row->confirmed_sku);
    }

    public function test_double_submit_keeps_single_verified_row(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $token = $this->tokenFrom($this->searchPage($user)->getContent());
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'TOP-1'])->assertRedirect();
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'TOP-1'])
            ->assertForbidden();
        $this->assertSame(1, SearchFeedback::count());
    }

    public function test_expired_token_is_rejected(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        Product::create(['sku' => 'TOP-1']);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => (string) Str::uuid(), 'sku' => 'TOP-1'])
            ->assertForbidden();
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_invalid_sku_is_rejected_before_storage(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $this->tokenFrom($this->searchPage($user)->getContent());
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'NOPE'])
            ->assertSessionHasErrors('sku');
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_deleted_product_sku_is_rejected(): void
    {
        config(['retrieval.feedback_enabled' => true]);
        $user = User::factory()->create(['role' => 'admin']);
        $token = $this->tokenFrom($this->searchPage($user)->getContent());
        Product::where('sku', 'TOP-2')->delete();
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'TOP-2'])
            ->assertSessionHasErrors('sku');
        $this->assertSame(0, SearchFeedback::count());
    }

    public function test_ai_failure_hides_confirmation_ui(): void
    {
        config(['retrieval.driver' => 'faiss', 'retrieval.feedback_enabled' => true]);
        Http::fake([rtrim(config('services.ai.url'), '/').'/search' => Http::response([], 503)]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('products.search'), ['image' => UploadedFile::fake()->image('q.jpg'), 'allow_feedback' => '1']);
        $response->assertOk()->assertViewHas('error')->assertDontSee('Konfirmasi SKU', false);
    }

    public function test_near_tie_asks_staff_to_pick_variant(): void
    {
        config(['retrieval.driver' => 'faiss', 'retrieval.feedback_enabled' => true]);
        Storage::fake('local');
        foreach (['SIZE-39' => 'Sandal 39', 'SIZE-40' => 'Sandal 40'] as $sku => $description) {
            $product = Product::create(['sku' => $sku, 'description' => $description]);
            $product->photos()->create(['path' => 'products/x.jpg', 'thumbnail_path' => 'thumbs/x.jpg']);
        }
        $this->fakeSearch([
            ['rank' => 1, 'sku' => 'SIZE-39', 'image_id' => 'front', 'score' => 0.91],
            ['rank' => 2, 'sku' => 'SIZE-40', 'image_id' => 'front', 'score' => 0.89],
        ], ['ambiguous' => true, 'ambiguity_reason' => 'same_family_variants',
            'ambiguity_alternatives' => [
                ['sku' => 'SIZE-39', 'score' => 0.91], ['sku' => 'SIZE-40', 'score' => 0.89]]]);
        $response = $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('products.search'), ['image' => UploadedFile::fake()->image('q.jpg'), 'allow_feedback' => '1']);
        $response->assertOk()->assertViewHas('ambiguity', fn ($ambiguity) => $ambiguity['ambiguous'] === true
            && $ambiguity['reason'] === 'same_family_variants'
            && array_column($ambiguity['alternatives'], 'sku') === ['SIZE-39', 'SIZE-40']);
        $response->assertSee('Hasil imbang', false);
        // Top-K and confirmation stay available for the staff pick.
        $response->assertSee('SIZE-40', false)->assertSee('Konfirmasi SKU', false);
    }

    public function test_clear_winner_shows_no_ambiguity_banner(): void
    {
        $response = $this->searchPage(User::factory()->create(['role' => 'admin']));
        $response->assertOk()->assertViewHas('ambiguity', fn ($ambiguity) => $ambiguity['ambiguous'] === false);
        $response->assertDontSee('Hasil imbang', false);
    }
}
