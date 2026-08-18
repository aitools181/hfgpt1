<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_page_is_available(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_active_user_can_login(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'admin@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);

        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }


    public function test_login_is_not_blocked_if_authentication_audit_table_is_temporarily_unavailable(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'audit-login@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        \Illuminate\Support\Facades\Schema::drop('audit_logs');

        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_is_not_blocked_if_last_login_tracking_column_is_temporarily_missing(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::query()->create(['name' => 'Admin', 'email' => 'tracking-login@example.test', 'password' => 'StrongPassword123!', 'status' => 'active']);
        \Illuminate\Support\Facades\Schema::table('users', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->dropColumn('last_login_at');
        });

        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
    }

    public function test_inactive_user_is_rejected(): void
    {
        $user = User::query()->create(['name' => 'Inactive', 'email' => 'inactive@example.test', 'password' => 'StrongPassword123!', 'status' => 'inactive']);
        $this->post('/login', ['email' => $user->email, 'password' => 'StrongPassword123!'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }
}
