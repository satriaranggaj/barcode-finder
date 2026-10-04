<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactPageTest extends TestCase
{
    use RefreshDatabase;
    public function test_contact_page_renders_with_safe_external_links(): void
    {
        $response = $this->get(route('contact'));

        $response->assertOk();
        $response->assertSee('Kontak', false);
        $response->assertSee('https://wa.me/628815695295', false);
        $response->assertSee('https://www.instagram.com/satria_rangga_j/', false);
        $response->assertSee('https://satriarangga.my.id/', false);
        $response->assertSee('rel="noopener noreferrer"', false);
        $response->assertSee('+62 881-5695-295', false);
        $response->assertSee('@satria_rangga_j', false);
        $response->assertSee('satriarangga.my.id', false);
    }

    public function test_navigation_contains_kontak_entry(): void
    {
        $index = $this->get(route('products.index'));
        $index->assertOk();
        $html = $index->getContent();
        $this->assertStringContainsString('href="'.route('contact').'"', $html);

        $active = $this->get(route('contact'))->getContent();
        $this->assertStringContainsString('bg-[#543019] text-white', $active);
    }
}
