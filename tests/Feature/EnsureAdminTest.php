<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_once(): void
    {
        config(['ledgerly.admin' => ['email' => 'boss@co.test', 'password' => 'a-long-password', 'name' => 'Boss']]);

        $this->artisan('app:ensure-admin')->assertSuccessful();
        $this->artisan('app:ensure-admin')->assertSuccessful();

        $this->assertSame(1, User::where('email', 'boss@co.test')->count());
        $this->assertSame(Role::Admin, User::first()->role);
        $this->postJson('/api/v1/auth/login', ['email' => 'boss@co.test', 'password' => 'a-long-password'])->assertOk();
    }

    public function test_skips_when_not_configured(): void
    {
        config(['ledgerly.admin' => ['email' => null, 'password' => null, 'name' => 'x']]);

        $this->artisan('app:ensure-admin')->assertSuccessful();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_rejects_short_password(): void
    {
        config(['ledgerly.admin' => ['email' => 'boss@co.test', 'password' => 'short', 'name' => 'x']]);

        $this->artisan('app:ensure-admin')->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_demo_seeder_does_not_touch_existing_books(): void
    {
        User::factory()->create();

        $this->seed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('accounts', 0);
    }
}
