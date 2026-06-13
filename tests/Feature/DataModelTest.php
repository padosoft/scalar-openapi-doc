<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AuthEvent;
use App\Enums\HttpVerb;
use App\Models\AuthLog;
use App\Models\ScalarServer;
use App\Models\User;
use App\Models\UserAllowedEndpoint;
use App\Models\UserAllowedTag;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DataModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_allowed_tag_is_unique_per_user(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Orders']);

        $this->expectException(QueryException::class);
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Orders']);
    }

    public function test_allowed_endpoint_is_unique_per_user_method_path(): void
    {
        $user = User::factory()->create();
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => 'GET', 'path' => '/orders/{id}']);

        $this->expectException(QueryException::class);
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => 'GET', 'path' => '/orders/{id}']);
    }

    public function test_grants_cascade_on_user_delete(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Orders']);
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => 'POST', 'path' => '/orders']);

        $user->delete();

        $this->assertDatabaseCount('user_allowed_tags', 0);
        $this->assertDatabaseCount('user_allowed_endpoints', 0);
    }

    public function test_auth_log_user_id_is_nulled_on_user_delete_but_email_survives(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.com']);
        $log = AuthLog::create([
            'user_id' => $user->id,
            'email' => 'gone@example.com',
            'event' => AuthEvent::Login,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
        ]);

        $user->delete();
        $log->refresh();

        $this->assertNull($log->user_id);
        $this->assertSame('gone@example.com', $log->email);
        $this->assertSame(AuthEvent::Login, $log->event);
    }

    public function test_auth_logs_table_has_no_updated_at_column(): void
    {
        $this->assertTrue(Schema::hasColumn('auth_logs', 'created_at'));
        $this->assertFalse(Schema::hasColumn('auth_logs', 'updated_at'));

        // The model does not manage updated_at.
        $this->assertNull(AuthLog::UPDATED_AT);
    }

    public function test_scalar_server_casts(): void
    {
        $server = ScalarServer::create([
            'url' => 'https://api.staging.example.com',
            'description' => 'Staging',
            'sort_order' => '5',
            'is_active' => 1,
        ]);
        $server->refresh();

        $this->assertSame(5, $server->sort_order);
        $this->assertTrue($server->is_active);
    }

    public function test_allowed_endpoint_method_is_cast_to_http_verb_enum(): void
    {
        $user = User::factory()->create();
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => HttpVerb::Get, 'path' => '/items']);

        $endpoint = UserAllowedEndpoint::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertInstanceOf(HttpVerb::class, $endpoint->method);
        $this->assertSame(HttpVerb::Get, $endpoint->method);
        $this->assertSame('GET', $endpoint->method->value);
    }

    public function test_http_verb_values_returns_all_eight_verbs(): void
    {
        $values = HttpVerb::values();

        $this->assertCount(8, $values);
        $this->assertContains('GET', $values);
        $this->assertContains('POST', $values);
        $this->assertContains('PUT', $values);
        $this->assertContains('PATCH', $values);
        $this->assertContains('DELETE', $values);
        $this->assertContains('HEAD', $values);
        $this->assertContains('OPTIONS', $values);
        $this->assertContains('TRACE', $values);
    }

    public function test_user_has_many_relations_return_correct_types(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Pets']);
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => HttpVerb::Post, 'path' => '/pets']);
        AuthLog::create([
            'user_id' => $user->id,
            'email' => $user->email,
            'event' => AuthEvent::Login,
        ]);

        $this->assertCount(1, $user->allowedTags);
        $this->assertInstanceOf(UserAllowedTag::class, $user->allowedTags->first());

        $this->assertCount(1, $user->allowedEndpoints);
        $this->assertInstanceOf(UserAllowedEndpoint::class, $user->allowedEndpoints->first());

        $this->assertCount(1, $user->authLogs);
        $this->assertInstanceOf(AuthLog::class, $user->authLogs->first());
    }
}
