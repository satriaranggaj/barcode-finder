<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $overrides = []): User
    {
        return User::factory()->create(['role' => $role, ...$overrides]);
    }

    private function validPayload(array $overrides = []): array
    {
        return [
            'name' => 'Nama Baru',
            'email' => fake()->unique()->safeEmail(),
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'role' => 'user',
            ...$overrides,
        ];
    }

    // Guest -----------------------------------------------------------

    public function test_guest_is_redirected_to_login_for_user_routes(): void
    {
        $target = $this->user('user');

        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
        $this->post(route('admin.users.store'), [])->assertRedirect(route('login'));
        $this->get(route('admin.users.edit', $target))->assertRedirect(route('login'));
        $this->put(route('admin.users.update', $target), [])->assertRedirect(route('login'));
        $this->delete(route('admin.users.destroy', $target))->assertRedirect(route('login'));
    }

    // Regular user ----------------------------------------------------

    public function test_regular_user_cannot_access_any_user_route(): void
    {
        $user = $this->user('user');
        $target = $this->user('user');
        $this->actingAs($user);

        $this->get(route('admin.users.index'))->assertForbidden();
        $this->post(route('admin.users.store'), $this->validPayload())->assertForbidden();
        $this->get(route('admin.users.edit', $target))->assertForbidden();
        $this->put(route('admin.users.update', $target), $this->validPayload())->assertForbidden();
        $this->delete(route('admin.users.destroy', $target))->assertForbidden();
        $this->get(route('admin.users.template'))->assertForbidden();
        $this->post(route('admin.users.import'), [])->assertForbidden();
    }

    public function test_regular_user_keeps_workspace_access(): void
    {
        $this->actingAs($this->user('user'));

        $this->get(route('admin.index'))->assertOk();
    }

    // Super admin -----------------------------------------------------

    public function test_super_admin_lists_users_without_delete_controls(): void
    {
        $this->actingAs($this->user('super_admin'));

        $response = $this->get(route('admin.users.index'))->assertOk();

        $response->assertSee('<option value="user"', false);
        $response->assertSee('<option value="super_admin"', false);
        $response->assertDontSee('<option value="developer"', false);
        $response->assertDontSee('Hapus</button>', false);
    }

    public function test_super_admin_can_create_user_and_super_admin_but_not_developer(): void
    {
        $this->actingAs($this->user('super_admin'));

        $this->post(route('admin.users.store'), $this->validPayload(['role' => 'user', 'email' => 'u1@example.com']))
            ->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'u1@example.com', 'role' => 'user']);

        $this->post(route('admin.users.store'), $this->validPayload(['role' => 'super_admin', 'email' => 'u2@example.com']))
            ->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'u2@example.com', 'role' => 'super_admin']);

        // Even a crafted payload cannot mint a developer.
        $response = $this->post(route('admin.users.store'), $this->validPayload(['role' => 'developer', 'email' => 'evil@example.com']));
        $response->assertSessionHasErrors('role');
        $this->assertDatabaseMissing('users', ['email' => 'evil@example.com']);
    }

    public function test_super_admin_cannot_touch_developer_accounts(): void
    {
        $actor = $this->user('super_admin');
        $developer = $this->user('developer');
        $this->actingAs($actor);

        $this->get(route('admin.users.edit', $developer))->assertForbidden();
        $this->put(route('admin.users.update', $developer), $this->validPayload(['email' => 'x@example.com']))
            ->assertForbidden();
        $this->assertSame('developer', $developer->fresh()->role);
    }

    public function test_super_admin_cannot_escalate_anyone_to_developer(): void
    {
        $actor = $this->user('super_admin');
        $target = $this->user('user');
        $this->actingAs($actor);

        // Another account…
        $this->put(route('admin.users.update', $target), $this->validPayload(['email' => 't@example.com', 'role' => 'developer']))
            ->assertSessionHasErrors('role');
        $this->assertSame('user', $target->fresh()->role);

        // …or themselves.
        $this->put(route('admin.users.update', $actor), $this->validPayload(['email' => 'me@example.com', 'role' => 'developer']))
            ->assertSessionHasErrors('role');
        $this->assertSame('super_admin', $actor->fresh()->role);
    }

    public function test_super_admin_can_edit_user_and_super_admin_profiles(): void
    {
        $this->actingAs($this->user('super_admin'));
        $target = $this->user('user');

        $this->put(route('admin.users.update', $target), $this->validPayload(['email' => 'new@example.com', 'role' => 'super_admin']))
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('super_admin', $target->fresh()->role);
    }

    public function test_super_admin_cannot_reach_delete_endpoint(): void
    {
        $this->actingAs($this->user('super_admin'));
        $target = $this->user('user');

        $this->delete(route('admin.users.destroy', $target))->assertForbidden();
        $this->assertModelExists($target);
    }

    public function test_super_admin_import_skips_developer_rows(): void
    {
        $this->actingAs($this->user('super_admin'));
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password,role\nOkay User,okay@example.com,secret123,user\nSneaky Dev,sneaky@example.com,secret123,developer\n"
        );

        $response = $this->post(route('admin.users.import'), ['file' => $file]);

        $response->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'okay@example.com', 'role' => 'user']);
        $this->assertDatabaseMissing('users', ['email' => 'sneaky@example.com']);
    }

    // Developer -------------------------------------------------------

    public function test_developer_manages_all_roles(): void
    {
        $this->actingAs($this->user('developer'));

        $response = $this->get(route('admin.users.index'))->assertOk();
        $response->assertSee('<option value="developer"', false);

        foreach (['user', 'super_admin', 'developer'] as $role) {
            $email = "new-{$role}@example.com";
            $this->post(route('admin.users.store'), $this->validPayload(['email' => $email, 'role' => $role]))
                ->assertRedirect(route('admin.users.index'));
            $this->assertDatabaseHas('users', ['email' => $email, 'role' => $role]);
        }
    }

    public function test_developer_can_edit_any_account(): void
    {
        $this->actingAs($this->user('developer'));
        $target = $this->user('user');

        $this->get(route('admin.users.edit', $target))->assertOk();
        $this->put(route('admin.users.update', $target), $this->validPayload(['email' => 'edited@example.com', 'role' => 'super_admin']))
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('super_admin', $target->fresh()->role);
    }

    public function test_developer_can_delete_other_accounts(): void
    {
        $this->actingAs($this->user('developer'));
        $victim = $this->user('user');

        $this->delete(route('admin.users.destroy', $victim))
            ->assertRedirect(route('admin.users.index'));
        $this->assertModelMissing($victim);
    }

    public function test_developer_cannot_delete_themselves(): void
    {
        $developer = $this->user('developer');
        $this->actingAs($developer);
        $this->user('developer', ['email' => 'other-dev@example.com']);

        $this->delete(route('admin.users.destroy', $developer))->assertForbidden();
        $this->assertModelExists($developer);
    }

    public function test_last_developer_cannot_be_deleted_or_demoted(): void
    {
        $developer = $this->user('developer');
        $this->actingAs($developer);

        $this->delete(route('admin.users.destroy', $developer))->assertForbidden();
        $this->put(route('admin.users.update', $developer), $this->validPayload(['email' => 'solo@example.com', 'role' => 'super_admin']))
            ->assertForbidden();
        $this->assertSame('developer', $developer->fresh()->role);
        $this->assertModelExists($developer);
    }

    public function test_non_last_developer_can_be_deleted_and_demoted(): void
    {
        $keeper = $this->user('developer');
        $other = $this->user('developer', ['email' => 'gone@example.com']);
        $this->actingAs($keeper);

        // Demote the other developer while a successor remains.
        $this->put(route('admin.users.update', $other), $this->validPayload(['email' => 'demoted@example.com', 'role' => 'user']))
            ->assertRedirect(route('admin.users.index'));
        $this->assertSame('user', $other->fresh()->role);

        // Deleting the other developer is allowed while the keeper remains.
        $second = $this->user('developer', ['email' => 'gone2@example.com']);
        $this->delete(route('admin.users.destroy', $second))->assertRedirect(route('admin.users.index'));
        $this->assertModelMissing($second);
        $this->assertModelExists($keeper);
    }

    public function test_developer_import_can_include_developer_rows(): void
    {
        $this->actingAs($this->user('developer'));
        $file = UploadedFile::fake()->createWithContent(
            'users.csv',
            "name,email,password,role\nNew Dev,newdev@example.com,secret123,developer\n"
        );

        $this->post(route('admin.users.import'), ['file' => $file])
            ->assertRedirect(route('admin.users.index'));
        $this->assertDatabaseHas('users', ['email' => 'newdev@example.com', 'role' => 'developer']);
    }
}
