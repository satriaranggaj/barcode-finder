<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PhotoSelectionUxTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_upload_has_file_list_without_small_thumbnail(): void
    {
        $admin = User::factory()->create(['role' => 'user']);
        $product = Product::create(['sku' => 'UX-1']);

        $html = $this->actingAs($admin)->get(route('admin.products.show', $product))->getContent();

        // Small 80x80 thumbnail presentation removed (caption + preview image).
        $this->assertStringNotContainsString('Preview foto SKU', $html);
        $this->assertStringNotContainsString('data-preview-image', $html);
        // Per-photo corner cancel + cancel-all remain; no filename list.
        $this->assertStringNotContainsString('data-selected-files', $html);
        $this->assertStringContainsString('data-selected-count', $html);
        $this->assertStringContainsString('data-cancel-selection', $html);
        $this->assertStringContainsString('Batalkan pilihan', $html);
        // Object-selection editor and submit remain functional.
        $this->assertStringContainsString('data-object-selection', $html);
        $this->assertStringContainsString('data-preview-submit', $html);
    }

    public function test_catalog_search_has_corner_cancel_control(): void
    {
        $html = $this->get(route('products.index'))->getContent();

        // No filename row: cancel lives on the editor panel corner (JS).
        $this->assertStringNotContainsString('data-search-filename', $html);
        $this->assertStringContainsString('data-object-selection', $html);
        // Search submit stays hidden until a photo is chosen.
        $this->assertStringContainsString('id="home-search-submit"', $html);
    }
}
