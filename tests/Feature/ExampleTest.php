<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
    }

    public function test_multiple_designs_are_displayed_in_a_slider(): void
    {
        $product = Product::create(['sku' => 'SLIDER']);
        $product->photos()->createMany([
            ['path' => 'products/one.jpg'],
            ['path' => 'products/two.jpg'],
        ]);

        $this->get(route('products.show', $product))
            ->assertSee('data-design-slider', false)
            ->assertSee('Desain 1 / 2')
            ->assertSee('Desain sebelumnya')
            ->assertSee('Desain berikutnya')
            ->assertSee('storage/products/one.jpg')
            ->assertSee('storage/products/two.jpg');
    }

    public function test_single_design_has_no_slider_controls(): void
    {
        $product = Product::create(['sku' => 'SINGLE']);
        $product->photos()->create(['path' => 'products/one.jpg']);

        $this->get(route('products.show', $product))
            ->assertSee('storage/products/one.jpg')
            ->assertDontSee('data-design-slider', false)
            ->assertDontSee('Desain berikutnya');
    }
}
