<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VisualSearchCommandsTest extends TestCase
{
    use RefreshDatabase;

    private string $healthUrl;

    protected function setUp(): void
    {
        parent::setUp();
        $this->healthUrl = rtrim(config('services.ai.url'), '/').'/health';
    }

    public function test_check_passes_on_healthy_index(): void
    {
        Http::fake([$this->healthUrl => ['status' => 'ok', 'references' => 19, 'search_ready' => true]]);

        $this->artisan('search:check')->assertSuccessful();
    }

    public function test_check_fails_on_empty_index(): void
    {
        Http::fake([$this->healthUrl => ['status' => 'ok', 'references' => 0, 'search_ready' => false]]);

        $this->artisan('search:check')->assertFailed();
    }

    public function test_check_fails_when_ai_unreachable(): void
    {
        Http::fake([$this->healthUrl => Http::response([], 503)]);

        $this->artisan('search:check')->assertFailed();
    }

    public function test_build_index_refuses_concurrent_build(): void
    {
        $lock = Cache::lock('visual-index-build', 86400);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('search:build-index')->assertFailed();
        } finally {
            $lock->release();
        }
    }

    public function test_admin_sees_pending_references_nudge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SKU123']);
        $product->photos()->create(['path' => 'products/a.jpg', 'disk' => 'public', 'index_status' => 'pending']);
        SearchFeedback::create([
            'user_id' => $admin->id, 'confirmed_product_id' => $product->id,
            'predicted_sku' => 'SKU123', 'confirmed_sku' => 'SKU123',
            'disk' => 'local', 'query_image_path' => 'verified-search/q.webp',
            'photo_hash' => str_repeat('a', 64), 'dhash' => str_repeat('0', 16),
            'training_status' => 'verified', 'reference_eligible' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('2 reference menunggu dipelajari AI.');

        Cache::put('visual-index-building', true, 60);
        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('AI sedang memperbarui index...');

        Cache::forget('visual-index-building');
        Cache::forever('visual-index-rebuild-required', 'photo 1 deleted');
        $this->actingAs($admin)->get(route('admin.index'))
            ->assertOk()
            ->assertSee('memerlukan full rebuild');
    }
}
