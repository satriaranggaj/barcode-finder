<?php

namespace Tests\Feature;

use App\Imports\ProductsImport;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductsImportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');

        Schema::create('products', function ($table): void {
            $table->id();
            $table->string('sku')->unique();
            $table->text('description')->nullable();
            $table->string('photo')->nullable();
            $table->text('embedding')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('products');

        parent::tearDown();
    }

    public function test_import_preserves_existing_product_when_sku_and_description_match(): void
    {
        $existingProduct = Product::create([
            'sku' => 'SKU-1',
            'description' => 'Deskripsi lama',
            'photo' => 'photo.jpg',
        ]);
        $import = new ProductsImport;

        $import->collection(new Collection([
            ['sku' => 'SKU-1', 'desc' => 'Deskripsi lama'],
        ]));

        $existingProduct->refresh();

        $this->assertSame('Deskripsi lama', $existingProduct->description);
        $this->assertSame('photo.jpg', $existingProduct->photo);
        $this->assertSame(1, Product::count());
    }

    public function test_import_updates_only_description_when_sku_matches(): void
    {
        Product::create([
            'sku' => 'SKU-1',
            'description' => 'Deskripsi lama',
            'photo' => 'photo.jpg',
        ]);
        $import = new ProductsImport;

        $import->collection(new Collection([
            ['sku' => 'SKU-1', 'desc' => 'Deskripsi baru'],
        ]));

        $updatedProduct = Product::where('sku', 'SKU-1')->firstOrFail();

        $this->assertSame('Deskripsi baru', $updatedProduct->description);
        $this->assertSame('photo.jpg', $updatedProduct->photo);
        $this->assertSame(1, Product::count());
    }

    public function test_import_creates_product_when_sku_is_new(): void
    {
        $import = new ProductsImport;

        $import->collection(new Collection([
            ['sku' => 'SKU-2', 'desc' => 'Produk baru'],
        ]));

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-2',
            'description' => 'Produk baru',
        ]);
    }

    public function test_import_processes_duplicate_skus_in_one_chunk_using_the_latest_description(): void
    {
        $import = new ProductsImport;

        $import->collection(new Collection([
            ['sku' => 'SKU-3', 'desc' => 'Deskripsi awal'],
            ['sku' => 'SKU-3', 'desc' => 'Deskripsi terbaru'],
        ]));

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-3',
            'description' => 'Deskripsi terbaru',
        ]);
        $this->assertSame(1, Product::where('sku', 'SKU-3')->count());
    }

    public function test_import_accepts_collection_rows_from_laravel_excel(): void
    {
        $import = new ProductsImport;

        $import->collection(new Collection([
            new Collection(['sku' => 'SKU-4', 'desc' => 'Produk dari Excel']),
        ]));

        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-4',
            'description' => 'Produk dari Excel',
        ]);
    }
}
