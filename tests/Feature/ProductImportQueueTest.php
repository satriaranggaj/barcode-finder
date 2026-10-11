<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Jobs\QueueImport;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProductImportQueueTest extends TestCase
{
    use RefreshDatabase;

    private function catalogUpload(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([['sku', 'desc']], $rows), null, 'A1', true);
        $path = tempnam(sys_get_temp_dir(), 'lensku-import-').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);
        $spreadsheet->disconnectWorksheets();

        return new UploadedFile($path, 'catalog.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    /** @return list<string> */
    private function excelTempFiles(): array
    {
        $files = glob(storage_path('framework/cache/laravel-excel/laravel-excel-*')) ?: [];

        return array_values($files);
    }

    private function actingAsSuperAdmin(): self
    {
        $this->actingAs(User::factory()->create(['role' => 'super_admin']));

        return $this;
    }

    public function test_import_is_queued_not_processed_synchronously(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        $before = $this->excelTempFiles();

        try {
            $response = $this->post(route('admin.import'), [
                'file' => $this->catalogUpload([['SKU-1', 'Satu'], ['SKU-2', 'Dua']]),
            ]);

            $response->assertRedirect(route('admin.index'));
            $response->assertSessionHas('success', 'File katalog diterima dan sedang diproses di antrean. Produk akan diperbarui secara bertahap.');
            Queue::assertPushed(QueueImport::class);
            // Nothing parsed synchronously: the HTTP request only dispatches.
            $this->assertSame(0, Product::count());
            // The upload was copied to the package's persistent local temp
            // path that every queued chunk will read.
            $new = array_diff($this->excelTempFiles(), $before);
            $this->assertCount(1, $new);
            $this->assertGreaterThan(0, filesize(reset($new)));
        } finally {
            foreach (array_diff($this->excelTempFiles(), $before) as $orphan) {
                @unlink($orphan);
            }
        }
    }

    public function test_queued_chunks_apply_existing_business_rules_end_to_end(): void
    {
        config(['queue.default' => 'database']);
        $this->actingAsSuperAdmin();
        $existing = Product::create(['sku' => 'SKU-1', 'description' => 'Lama', 'photo' => 'keep.jpg']);
        $existing->attributes()->create(['key' => 'color', 'value' => 'Merah', 'source' => ProductAttribute::SOURCE_MANUAL]);
        $before = $this->excelTempFiles();

        $response = $this->post(route('admin.import'), ['file' => $this->catalogUpload([
            ['SKU-1', 'Baru'],
            ['SKU-2', 'Produk baru'],
            ['', 'Tanpa SKU'],
            ['SKU-3', 'Awal'],
            ['SKU-3', 'Terbaru'],
        ])]);
        $response->assertRedirect(route('admin.index'));
        $this->assertSame(1, Product::count());

        $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

        // Updated in place: same id, new description, unrelated fields kept.
        $this->assertSame('Baru', $existing->fresh()->description);
        $this->assertSame('keep.jpg', $existing->fresh()->photo);
        $this->assertSame($existing->id, Product::where('sku', 'SKU-1')->sole()->id);
        // Manual attributes preserved; parser attributes refreshed.
        $this->assertTrue($existing->attributes()->where('source', ProductAttribute::SOURCE_MANUAL)->where('key', 'color')->exists());
        // New rows created, blanks ignored, in-file duplicates use latest.
        $this->assertDatabaseHas('products', ['sku' => 'SKU-2', 'description' => 'Produk baru']);
        $this->assertDatabaseHas('products', ['sku' => 'SKU-3', 'description' => 'Terbaru']);
        $this->assertSame(1, Product::where('sku', 'SKU-3')->count());
        $this->assertSame(3, Product::count());
        $this->assertSame(0, \Illuminate\Support\Facades\DB::table('failed_jobs')->count());
        // Package cleanup ran after the final chunk: no temp file left behind.
        $this->assertSame([], array_diff($this->excelTempFiles(), $before));
    }

    public function test_dispatch_failure_reports_error_without_false_success(): void
    {
        Queue::fake();
        $this->actingAsSuperAdmin();
        Excel::shouldReceive('queueImport')->once()->andThrow(new \RuntimeException('queue down'));

        $response = $this->post(route('admin.import'), [
            'file' => $this->catalogUpload([['SKU-9', 'Sembilan']]),
        ]);

        $response->assertRedirect(route('admin.index'));
        $response->assertSessionHasErrors('file');
        $response->assertSessionMissing('success');
        $this->assertSame(0, Product::count());
    }
}
