<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\SearchFeedback;
use App\Models\User;
use App\Services\SearchEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImageSecurityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build an upload whose MIME is detected from real bytes (fake uploads
     * in test mode trust the extension, bypassing content sniffing).
     */
    private function realUpload(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'sec').'-'.$name;
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    public function test_non_image_content_rejected_without_ai_call_or_storage(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'SEC-1']);
        $evil = $this->realUpload('photo.jpg', '<?php echo "pwn";');
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$evil]])
            ->assertUnprocessable();
        $this->assertSame(0, $product->photos()->count());
        $this->assertSame([], Storage::disk('public')->allFiles());
        Http::assertNothingSent();
    }

    public function test_oversized_upload_rejected_before_processing(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        $product = Product::create(['sku' => 'SEC-2']);
        $big = UploadedFile::fake()->create('huge.jpg', 11 * 1024);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$big]])
            ->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_search_rejects_non_image_and_oversized_uploads(): void
    {
        Http::preventStrayRequests();
        $this->post(route('products.search'), ['image' => $this->realUpload('q.jpg', 'not-an-image')])
            ->assertSessionHasErrors('image');
        $this->post(route('products.search'), ['image' => UploadedFile::fake()->create('q.jpg', 11 * 1024)])
            ->assertSessionHasErrors('image');
        Http::assertNothingSent();
    }

    public function test_hostile_filename_never_reaches_storage_path(): void
    {
        config(['retrieval.driver' => 'faiss']);
        Storage::fake('public');
        Http::preventStrayRequests();
        $file = UploadedFile::fake()->image('x.jpg');
        Http::fake([rtrim(config('services.ai.url'), '/').'/prepare' => Http::response([
            'status' => 'optimized', 'extension' => 'jpg',
            'master' => base64_encode(file_get_contents($file->getRealPath())),
        ])]);
        $product = Product::create(['sku' => 'SEC-3']);
        // Client-controlled original name with traversal segments.
        $hostile = new UploadedFile($file->getRealPath(), '../../evil.php.jpg', 'image/jpeg', null, true);
        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->postJson(route('admin.products.photo.store', $product), ['images' => [$hostile]])
            ->assertOk();
        $photo = $product->photos()->sole();
        foreach ([$photo->path, $photo->master_path] as $path) {
            $this->assertMatchesRegularExpression('#^products/[0-9a-f-]{36}-(master|catalog|thumbnail)\.jpg$#', $path);
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringNotContainsString('evil', $path);
        }
    }

    public function test_verified_and_staged_images_stay_off_public_disk(): void
    {
        config(['retrieval.feedback_enabled' => true, 'retrieval.query_temp_disk' => 'local', 'retrieval.private_disk' => 'local']);
        Storage::fake('public');
        Storage::fake('local');
        $user = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'SEC-4']);
        $image = UploadedFile::fake()->image('q.jpg', 300, 300);
        $token = app(SearchEvidence::class)->stage([
            'results' => [['sku' => 'SEC-4', 'score' => 0.8]],
            'feedback' => [
                'master' => base64_encode(file_get_contents($image->getRealPath())),
                'photo_hash' => str_repeat('e', 64), 'dhash' => str_repeat('6', 16),
                'blur' => 120, 'min_side' => 300, 'crop_pixels' => 90000, 'gray_std' => 45,
            ],
        ], $user->id);
        $this->actingAs($user)->post(route('search.feedback'), ['token' => $token, 'sku' => 'SEC-4'])->assertRedirect();
        $row = SearchFeedback::sole();
        $this->assertSame('local', $row->disk);
        Storage::disk('local')->assertExists($row->query_image_path);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_example_env_commits_no_real_secrets(): void
    {
        $example = file_get_contents(base_path('.env.example'));
        $this->assertNotEmpty($example);
        foreach (['AWS_SECRET_ACCESS_KEY=', 'DB_PASSWORD='] as $prefix) {
            foreach (explode("\n", $example) as $line) {
                if (str_starts_with(trim($line), $prefix) && ! str_starts_with(trim($line), '#')) {
                    $this->assertMatchesRegularExpression('/=$|=<.*>|=null$/', trim($line), "Real secret committed: {$prefix}");
                }
            }
        }
        // A local .env may exist for development, but it must never be tracked.
        exec('git -C '.escapeshellarg(base_path()).' ls-files --error-unmatch .env 2>&1', $output, $code);
        $this->assertNotSame(0, $code, '.env must not be committed');
    }
}
