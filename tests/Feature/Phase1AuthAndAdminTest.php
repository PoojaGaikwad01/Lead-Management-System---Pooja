<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class Phase1AuthAndAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login_and_there_is_no_registration(): void
    {
        $this->get('/inbox')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Log in');
        $this->get('/register')->assertNotFound();
    }

    public function test_active_user_can_log_in_and_out(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/inbox');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_inactive_user_cannot_log_in(): void
    {
        $user = User::factory()->inactive()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_user_deactivated_mid_session_is_logged_out(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get('/inbox')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/inbox')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_branch_manager_cannot_access_admin_pages(): void
    {
        $manager = User::factory()->create();

        $this->actingAs($manager)->get('/branches')->assertForbidden();
        $this->actingAs($manager)->get('/users')->assertForbidden();
        $this->actingAs($manager)->post('/branches', ['name' => 'X', 'city' => 'Y'])->assertForbidden();
        $this->actingAs($manager)->get('/inbox')->assertOk()->assertDontSee('href="'.route('users.index').'"', false);
    }

    public function test_branch_scope_limits_manager_to_own_branch(): void
    {
        $manager = User::factory()->create();
        Branch::factory()->count(2)->create();
        $admin = User::factory()->admin()->create();

        $this->assertSame([$manager->branch_id], Branch::visibleTo($manager)->pluck('id')->all());
        $this->assertSame(3, Branch::visibleTo($admin)->count());
    }

    public function test_admin_can_crud_branches_and_assign_manager(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/branches', ['name' => 'Andheri', 'city' => 'Mumbai', 'is_active' => '1'])->assertRedirect();
        $branch = Branch::where('name', 'Andheri')->firstOrFail();
        $this->assertTrue($branch->is_active);

        $this->actingAs($admin)->put("/branches/{$branch->id}", ['name' => 'Andheri West', 'city' => 'Mumbai'])->assertRedirect('/branches');
        $this->assertFalse($branch->fresh()->is_active);

        $manager = User::factory()->create();
        $this->actingAs($admin)->post("/branches/{$branch->id}/managers", ['user_id' => $manager->id])->assertSessionHasNoErrors();
        $this->assertSame($branch->id, $manager->fresh()->branch_id);

        // Can't assign an admin as branch manager.
        $this->actingAs($admin)->post("/branches/{$branch->id}/managers", ['user_id' => $admin->id])->assertSessionHasErrors('user_id');

        // Can't delete a branch that still has managers.
        $this->actingAs($admin)->delete("/branches/{$branch->id}")->assertSessionHasErrors('branch');
        $manager->delete();
        $this->actingAs($admin)->delete("/branches/{$branch->id}")->assertRedirect('/branches');
        $this->assertModelMissing($branch);
    }

    public function test_admin_can_create_update_reset_password_and_toggle_users(): void
    {
        $admin = User::factory()->admin()->create();
        $branch = Branch::factory()->create();

        // Branch manager requires a branch.
        $this->actingAs($admin)->post('/users', [
            'name' => 'No Branch', 'email' => 'nb@example.com', 'role' => 'branch_manager',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertSessionHasErrors('branch_id');

        $this->actingAs($admin)->post('/users', [
            'name' => 'Priya', 'email' => 'priya@example.com', 'phone' => '+91 9876543210',
            'role' => 'branch_manager', 'branch_id' => $branch->id, 'is_active' => '1',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertRedirect('/users');
        $priya = User::where('email', 'priya@example.com')->firstOrFail();
        $this->assertSame(Role::BranchManager, $priya->role);
        $this->assertSame($branch->id, $priya->branch_id);

        // Promoting to admin clears the branch.
        $this->actingAs($admin)->put("/users/{$priya->id}", [
            'name' => 'Priya', 'email' => 'priya@example.com', 'role' => 'admin', 'branch_id' => $branch->id, 'is_active' => '1',
        ])->assertRedirect('/users');
        $this->assertNull($priya->fresh()->branch_id);

        $this->actingAs($admin)->put("/users/{$priya->id}/password", ['password' => 'newpass99', 'password_confirmation' => 'newpass99'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('newpass99', $priya->fresh()->password));

        $this->actingAs($admin)->patch("/users/{$priya->id}/toggle");
        $this->assertFalse($priya->fresh()->is_active);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->patch("/users/{$admin->id}/toggle")->assertSessionHasErrors('user');
        $this->actingAs($admin)->delete("/users/{$admin->id}")->assertSessionHasErrors('user');
        $this->actingAs($admin)->put("/users/{$admin->id}", [
            'name' => 'Me', 'email' => $admin->email, 'role' => 'branch_manager', 'branch_id' => Branch::factory()->create()->id,
        ]);

        $admin->refresh();
        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->is_active);
    }

    public function test_all_pages_render(): void
    {
        $this->seed();
        $admin = User::where('role', 'admin')->first();
        $manager = User::where('role', 'branch_manager')->first();
        $branch = Branch::first();

        foreach (['/inbox', '/reports', '/branches', '/branches/create', "/branches/{$branch->id}/edit",
                  '/users', '/users/create', "/users/{$manager->id}/edit", "/users/{$admin->id}/edit", '/users?q=a&role=branch_manager'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
        $this->actingAs($manager)->get('/inbox')->assertOk()->assertSee($branch->name);
        $this->actingAs($manager)->get('/reports')->assertOk();
    }

    public function test_seeder_creates_admin_three_branches_and_managers(): void
    {
        $this->seed();
        $this->seed(); // idempotent

        $this->assertSame(1, User::where('role', 'admin')->count());
        $this->assertSame(3, Branch::count());
        Branch::all()->each(fn ($b) => $this->assertSame(1, $b->managers()->count()));
    }
}
