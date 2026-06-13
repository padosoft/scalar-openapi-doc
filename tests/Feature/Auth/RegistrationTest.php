<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_is_disabled(): void
    {
        // Users are admin-provisioned; the registration feature must stay off.
        $this->assertFalse(Features::enabled(Features::registration()));
    }

    public function test_registration_routes_are_not_registered(): void
    {
        $this->assertFalse(Route::has('register'));
        $this->assertFalse(Route::has('register.store'));
    }

    public function test_registration_endpoint_is_unavailable(): void
    {
        // The POST handler does not exist when registration is disabled.
        $response = $this->post('/register', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $this->assertContains($response->getStatusCode(), [404, 405]);
        $this->assertGuest();
        $this->assertDatabaseMissing('users', ['email' => 'intruder@example.com']);
    }
}
