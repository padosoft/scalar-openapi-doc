<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\ReplaceUserAccessAction;
use App\Models\ScalarServer;
use App\Models\User;
use App\Models\UserAllowedEndpoint;
use App\Models\UserAllowedTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplaceUserAccessActionTest extends TestCase
{
    use RefreshDatabase;

    private function action(): ReplaceUserAccessAction
    {
        return app(ReplaceUserAccessAction::class);
    }

    public function test_replaces_existing_grants_with_the_new_set(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Old']);
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => 'GET', 'path' => '/old']);

        $this->action()->handle(
            $user,
            ['Orders', 'Products'],
            [['method' => 'post', 'path' => '/orders'], ['method' => 'GET', 'path' => '/products/{id}']],
        );

        $this->assertEqualsCanonicalizing(
            ['Orders', 'Products'],
            $user->allowedTags()->pluck('tag')->all(),
        );
        // Method is normalised to uppercase.
        $this->assertEqualsCanonicalizing(
            ['POST /orders', 'GET /products/{id}'],
            $user->allowedEndpoints()->get()->map(fn ($e) => $e->method->value.' '.$e->path)->all(),
        );
        $this->assertDatabaseMissing('user_allowed_tags', ['tag' => 'Old']);
        $this->assertDatabaseMissing('user_allowed_endpoints', ['path' => '/old']);
    }

    public function test_empty_arrays_clear_all_grants(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Orders']);
        UserAllowedEndpoint::create(['user_id' => $user->id, 'method' => 'GET', 'path' => '/orders']);

        $this->action()->handle($user, [], []);

        $this->assertSame(0, $user->allowedTags()->count());
        $this->assertSame(0, $user->allowedEndpoints()->count());
    }

    public function test_duplicate_inputs_are_deduplicated(): void
    {
        $user = User::factory()->create();

        $this->action()->handle(
            $user,
            ['Orders', 'Orders', ''],
            [['method' => 'GET', 'path' => '/x'], ['method' => 'get', 'path' => '/x']],
        );

        $this->assertSame(1, $user->allowedTags()->count());
        $this->assertSame(1, $user->allowedEndpoints()->count());
    }

    public function test_replaces_server_grants_with_the_new_set(): void
    {
        $user = User::factory()->create();
        $a = ScalarServer::create(['url' => 'https://a.local', 'sort_order' => 1, 'is_active' => true]);
        $b = ScalarServer::create(['url' => 'https://b.local', 'sort_order' => 2, 'is_active' => true]);
        $c = ScalarServer::create(['url' => 'https://c.local', 'sort_order' => 3, 'is_active' => true]);
        $user->allowedServers()->attach($a->id);

        // Duplicate + string ids are normalised; the old grant (a) is replaced.
        $this->action()->handle($user, [], [], [$b->id, $c->id, (string) $c->id]);

        $this->assertEqualsCanonicalizing(
            [$b->id, $c->id],
            $user->allowedServers()->pluck('scalar_servers.id')->all(),
        );
        $this->assertDatabaseMissing('user_allowed_servers', [
            'user_id' => $user->id,
            'scalar_server_id' => $a->id,
        ]);
    }

    public function test_empty_server_ids_clear_server_grants(): void
    {
        $user = User::factory()->create();
        $server = ScalarServer::create(['url' => 'https://a.local', 'sort_order' => 1, 'is_active' => true]);
        $user->allowedServers()->attach($server->id);

        $this->action()->handle($user, [], [], []);

        $this->assertSame(0, $user->allowedServers()->count());
    }

    public function test_one_users_grants_do_not_affect_another(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        UserAllowedTag::create(['user_id' => $b->id, 'tag' => 'Keep']);

        $this->action()->handle($a, ['Orders'], []);

        $this->assertSame(['Keep'], $b->allowedTags()->pluck('tag')->all());
        $this->assertSame(['Orders'], $a->allowedTags()->pluck('tag')->all());
    }

    public function test_is_atomic_on_failure(): void
    {
        $user = User::factory()->create();
        UserAllowedTag::create(['user_id' => $user->id, 'tag' => 'Original']);

        // A null path violates the NOT NULL column during the endpoint insert,
        // after tags were already deleted/re-inserted. The transaction must roll
        // back so the pre-existing rows survive (not be left deleted).
        try {
            /** @phpstan-ignore-next-line argument.type (deliberately invalid to force a rollback) */
            $this->action()->handle($user, ['New'], [['method' => 'GET', 'path' => null]]);
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame(['Original'], $user->allowedTags()->pluck('tag')->all());
    }
}
