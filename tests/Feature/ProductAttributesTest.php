<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\User;
use App\Services\ProductAttributes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductAttributesTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_persists_parsed_rows_with_provenance(): void
    {
        $product = Product::create(['sku' => 'SKU-A', 'description' => 'Obeng Plus PH2 150MM hitam 2PCS']);
        // Plus -> PHILLIPS plus specific PH2 code: 2 drive rows + measurement + color + quantity.
        $this->assertSame(5, ProductAttributes::refreshFromDescription($product));
        $rows = $product->attributes()->orderBy('key')->orderBy('value')->get();
        $this->assertSame(['color', 'drive', 'drive', 'measurement', 'quantity'], $rows->pluck('key')->all());
        $this->assertSame(['HITAM', 'PH2', 'PHILLIPS', '150MM', '2PCS'], $rows->pluck('value')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->source === ProductAttribute::SOURCE_DESCRIPTION_PARSER));
        $this->assertSame(['color', 'drive', 'drive', 'measurement', 'quantity'], $rows->pluck('rule')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->confidence === null));
        // Raw description stays authoritative and untouched.
        $this->assertSame('Obeng Plus PH2 150MM hitam 2PCS', $product->fresh()->description);
    }

    public function test_refresh_is_idempotent(): void
    {
        $product = Product::create(['sku' => 'SKU-A', 'description' => 'Kuas 2" putih JC414']);
        ProductAttributes::refreshFromDescription($product);
        ProductAttributes::refreshFromDescription($product);
        $this->assertSame(3, $product->attributes()->count());
    }

    public function test_description_change_regenerates_without_hurting_manual_rows(): void
    {
        $product = Product::create(['sku' => 'SKU-A', 'description' => 'Obeng PH1 100MM']);
        $product->attributes()->create(['key' => 'drive', 'value' => 'PH2', 'source' => ProductAttribute::SOURCE_MANUAL]);
        $product->update(['description' => 'Kuas 2" merah']);
        ProductAttributes::refreshFromDescription($product->fresh());

        $rows = $product->attributes()->orderBy('source')->orderBy('key')->get();
        $this->assertSame(['description_parser', 'description_parser', 'manual'], $rows->pluck('source')->all());
        // Stale parser values are gone; the trusted manual row survives.
        $this->assertSame(['color', 'measurement', 'drive'], $rows->pluck('key')->all());
        $this->assertSame(['MERAH', '2"', 'PH2'], $rows->pluck('value')->all());
    }

    public function test_empty_description_clears_parser_rows_only(): void
    {
        $product = Product::create(['sku' => 'SKU-A', 'description' => 'Obeng PH1']);
        $product->attributes()->create(['key' => 'note', 'value' => 'rak-3', 'source' => ProductAttribute::SOURCE_MANUAL]);
        $product->update(['description' => null]);
        $this->assertSame(0, ProductAttributes::refreshFromDescription($product->fresh()));
        $this->assertSame(['manual'], $product->attributes()->pluck('source')->all());
    }

    public function test_controller_store_and_update_refresh_parser_rows(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('admin.products.store'), ['sku' => 'SKU-A', 'description' => 'Obeng PH2 150MM'])
            ->assertRedirect();
        $product = Product::where('sku', 'SKU-A')->sole();
        $this->assertSame(['PH2', '150MM'], $product->attributes()->orderBy('key')->pluck('value')->all());

        $this->actingAs($admin)->put(route('admin.products.update', $product), ['sku' => 'SKU-A', 'description' => 'Kuas 1"'])
            ->assertRedirect();
        $this->assertSame(['1"'], $product->attributes()->pluck('value')->all());
    }

    public function test_refresh_command_regenerates_idempotently(): void
    {
        Product::create(['sku' => 'SKU-A', 'description' => 'Obeng PH2']);
        Product::create(['sku' => 'SKU-B', 'description' => 'Kuas 2"']);
        $this->artisan('products:refresh-attributes')->assertSuccessful();
        $this->artisan('products:refresh-attributes')->assertSuccessful();
        $this->assertSame(2, ProductAttribute::count());
        $this->artisan('products:refresh-attributes', ['--sku' => 'SKU-A'])->assertSuccessful();
        $this->assertSame(2, ProductAttribute::count());
    }

    public function test_migration_is_reversible(): void
    {
        $this->assertTrue(Schema::hasTable('product_attributes'));
        $migration = require base_path('database/migrations/2026_09_17_020000_create_product_attributes_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasTable('product_attributes'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('product_attributes'));
    }
}
