<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    public function test_super_admin_can_edit_user_without_changing_password_when_password_is_empty(): void
    {
        $superAdmin = User::factory()->create(['role' => 'super_admin']);
        $user = User::factory()->create([
            'name' => 'Nama Salah',
            'email' => 'wrong@example.com',
            'role' => 'admin',
            'password' => 'old-password',
        ]);
        $oldPassword = $user->password;

        $response = $this->actingAs($superAdmin)->put(route('admin.users.update', $user), [
            'name' => 'Nama Benar',
            'email' => 'correct@example.com',
            'role' => 'super_admin',
            'password' => '',
            'password_confirmation' => '',
        ]);

        $response->assertRedirect(route('admin.users.index'));
        $user->refresh();
        $this->assertSame('Nama Benar', $user->name);
        $this->assertSame('correct@example.com', $user->email);
        $this->assertSame('super_admin', $user->role);
        $this->assertSame($oldPassword, $user->password);
    }

    public function test_regular_admin_cannot_edit_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $user = User::factory()->create();

        $response = $this->actingAs($admin)->get(route('admin.users.edit', $user));

        $response->assertForbidden();
    }

    public function test_regular_admin_can_find_items_with_multiple_designs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'MULTI-1', 'description' => 'Motif bunga']);
        $product->photos()->createMany([
            ['path' => 'products/first.jpg'],
            ['path' => 'products/second.jpg'],
        ]);
        Product::create(['sku' => 'EMPTY-1']);

        $response = $this->actingAs($admin)->get(route('admin.index', ['tab' => 'with-photo', 'q' => 'bunga']));

        $response->assertViewHas('tab', 'with-photo')
            ->assertSee('Sudah ada foto')->assertSee('MULTI-1')->assertSee('2 design')
            ->assertSee(route('admin.products.show', $product))->assertDontSee('EMPTY-1');
    }

    public function test_regular_admin_can_open_and_edit_an_item_with_photos(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'EDIT-1', 'description' => 'Deskripsi lama']);
        $photo = $product->photos()->create(['path' => 'products/existing.jpg']);
        $this->actingAs($admin)->get(route('admin.products.show', $product))
            ->assertSee('Edit data item')->assertSee('Tambah design foto')
            ->assertSee(route('admin.photos.destroy', $photo));

        $response = $this->put(route('admin.products.update', $product), [
            'sku' => 'EDIT-NEW', 'description' => 'Deskripsi baru',
        ]);

        $response->assertRedirect(route('admin.products.show', $product))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'EDIT-NEW', 'description' => 'Deskripsi baru']);
        $this->assertModelExists($photo);
    }

    public function test_regular_admin_can_add_designs_without_removing_existing_photos(): void
    {
        Storage::fake('public');
        Http::preventStrayRequests();
        Http::fake([rtrim(config('services.ai.url'), '/').'/features' => Http::response(VisualSearchTest::representation())]);
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'UPLOAD-1']);
        $existing = $product->photos()->create(['path' => 'products/existing.jpg']);
        Storage::disk('public')->put($existing->path, 'existing');

        $response = $this->actingAs($admin)->post(route('admin.products.photo.store', $product), [
            'images' => [UploadedFile::fake()->image('one.jpg'), UploadedFile::fake()->image('two.png')],
        ]);

        $response->assertRedirect(route('admin.products.show', $product))->assertSessionHasNoErrors();
        $this->assertCount(3, $product->photos);
        $this->assertModelExists($existing);
        foreach ($product->photos as $photo) {
            Storage::disk('public')->assertExists($photo->path);
        }
        Http::assertSentCount(2);
    }

    public function test_regular_admin_can_delete_only_the_selected_design(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'DELETE-1']);
        $selected = $product->photos()->create(['path' => 'products/selected.jpg']);
        $retained = $product->photos()->create(['path' => 'products/retained.jpg']);
        Storage::disk('public')->put($selected->path, 'selected');
        Storage::disk('public')->put($retained->path, 'retained');

        $response = $this->actingAs($admin)->delete(route('admin.photos.destroy', $selected));

        $response->assertRedirect(route('admin.products.show', $product));
        $this->assertModelMissing($selected);
        $this->assertModelExists($retained);
        Storage::disk('public')->assertMissing($selected->path);
        Storage::disk('public')->assertExists($retained->path);
    }

    public function test_guest_cannot_modify_items_or_photos(): void
    {
        $product = Product::create(['sku' => 'PROTECTED-1']);
        $photo = $product->photos()->create(['path' => 'products/protected.jpg']);

        $this->get(route('admin.products.show', $product))->assertRedirect(route('login'));
        $this->put(route('admin.products.update', $product), ['sku' => 'CHANGED'])->assertRedirect(route('login'));
        $this->post(route('admin.products.photo.store', $product))->assertRedirect(route('login'));
        $this->delete(route('admin.photos.destroy', $photo))->assertRedirect(route('login'));

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'PROTECTED-1']);
        $this->assertModelExists($photo);
    }

    public function test_regular_admin_cannot_save_a_duplicate_sku(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $product = Product::create(['sku' => 'ORIGINAL']);
        Product::create(['sku' => 'TAKEN']);

        $response = $this->actingAs($admin)->put(route('admin.products.update', $product), ['sku' => 'TAKEN']);

        $response->assertSessionHasErrors('sku');
        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'ORIGINAL']);
    }

    public function test_non_admin_cannot_manage_items(): void
    {
        $user = User::factory()->create(['role' => 'viewer']);
        $product = Product::create(['sku' => 'RESTRICTED']);
        $photo = $product->photos()->create(['path' => 'products/restricted.jpg']);
        $this->actingAs($user);

        $this->get(route('admin.index'))->assertForbidden();
        $this->get(route('admin.products.show', $product))->assertForbidden();
        $this->put(route('admin.products.update', $product), ['sku' => 'CHANGED'])->assertForbidden();
        $this->post(route('admin.products.photo.store', $product))->assertForbidden();
        $this->delete(route('admin.photos.destroy', $photo))->assertForbidden();

        $this->assertDatabaseHas('products', ['id' => $product->id, 'sku' => 'RESTRICTED']);
        $this->assertModelExists($photo);
    }
}
