<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminUserTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_download_user_template(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        $response = $this->actingAs($superAdmin)->get(route('admin.users.template'));

        $response->assertDownload('template-user-admin.xlsx');
    }

    public function test_super_admin_can_import_users_from_the_template_format(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password,role\nWarehouse Admin,warehouse@example.com,secret123,admin\n"
        );

        $response = $this->actingAs($superAdmin)->post(route('admin.users.import'), [
            'file' => $file,
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', [
            'email' => 'warehouse@example.com',
            'role' => 'admin',
        ]);
        $this->assertTrue(Hash::check('secret123', User::where('email', 'warehouse@example.com')->value('password')));
    }

    public function test_regular_admin_cannot_import_users(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->get(route('admin.users.template'));

        $response->assertForbidden();
    }

    public function test_regular_admin_can_add_a_product_item(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($admin)->post(route('admin.products.store'), [
            'sku' => 'SKU-ADMIN-1',
            'description' => 'Item yang ditambahkan admin',
        ]);

        $response->assertRedirect(route('admin.index'));
        $this->assertDatabaseHas('products', [
            'sku' => 'SKU-ADMIN-1',
            'description' => 'Item yang ditambahkan admin',
        ]);
    }
}
