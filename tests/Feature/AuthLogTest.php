<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuthEvent;
use App\Models\AuthLog;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

final class AuthLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_authentication_events_are_written_to_auth_logs(): void
    {
        $user = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertDatabaseHas('auth_logs', [
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthEvent::Login->value,
        ]);

        $this->actingAs($user)
            ->post(route('logout'));

        $this->assertDatabaseHas('auth_logs', [
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthEvent::Logout->value,
        ]);

        $failingUser = User::factory()->create();

        $this->post(route('login.store'), [
            'email' => $failingUser->email,
            'password' => 'wrong-password',
        ]);

        $this->assertDatabaseHas('auth_logs', [
            'email' => $failingUser->email,
            'event' => AuthEvent::Failed->value,
        ]);
    }

    public function test_auth_logs_read_only_route_is_admin_only_and_filtered(): void
    {
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $this->seed(DatabaseSeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole($adminRole);

        $viewer = User::factory()->create();
        $viewer->assignRole($this->viewerRole());

        $this->actingAs($viewer)->get('/auth-logs')->assertForbidden();
        $this->actingAs($viewer)->post('/auth-logs')->assertStatus(405);

        AuthLog::query()->insert([
            [
                'user_id' => null,
                'email' => 'login@example.com',
                'event' => AuthEvent::Login->value,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now(),
            ],
            [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'event' => AuthEvent::Failed->value,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now(),
            ],
            [
                'user_id' => $admin->id,
                'email' => $admin->email,
                'event' => AuthEvent::Logout->value,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now(),
            ],
        ]);

        $response = $this->actingAs($admin)->get('/auth-logs?event=login');

        $response
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.event', 'login')
                ->where('rows.total', 1)
                ->where('rows.data.0.event', AuthEvent::Login->value)
            );
    }

    public function test_auth_logs_prune_command_removes_stale_rows(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-15 09:00:00'));

        AuthLog::query()->insert([
            [
                'user_id' => null,
                'email' => 'old@example.com',
                'event' => AuthEvent::Login->value,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now()->subDays(40)->toDateTimeString(),
            ],
            [
                'user_id' => null,
                'email' => 'fresh@example.com',
                'event' => AuthEvent::Login->value,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'phpunit',
                'created_at' => now()->subDays(5)->toDateTimeString(),
            ],
        ]);

        $this->artisan('auth-logs:prune --days=30')
            ->assertExitCode(0);

        Carbon::setTestNow();

        $this->assertDatabaseMissing('auth_logs', ['email' => 'old@example.com']);
        $this->assertDatabaseHas('auth_logs', ['email' => 'fresh@example.com']);
    }

    private function viewerRole(): string
    {
        $adminRole = (string) config('openapi.admin_role', 'admin');
        $viewerRoles = array_values(array_filter(
            (array) config('openapi.viewer_roles', []),
            static fn (mixed $role): bool => is_string($role) && trim($role) !== '' && trim($role) !== $adminRole,
        ));

        if ($viewerRoles !== []) {
            $viewerRole = (string) $viewerRoles[0];
            Role::findOrCreate($viewerRole, (string) config('auth.defaults.guard', 'web'));

            return $viewerRole;
        }

        $fallbackRole = 'viewer';
        Role::findOrCreate($fallbackRole, (string) config('auth.defaults.guard', 'web'));

        return $fallbackRole;
    }
}
