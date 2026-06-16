<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\ReplaceUserAccessAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\ScalarServer;
use App\Models\User;
use App\Services\OpenApiSpecService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/users/index', [
            'users' => $this->serializeUsers(User::with(['roles', 'allowedTags', 'allowedEndpoints'])->orderByDesc('id')->get()),
        ]);
    }

    public function create(OpenApiSpecService $openApiSpecService): Response
    {
        return Inertia::render('admin/users/form', [
            'user' => null,
            'roles' => $this->allRoles(),
            'openapi' => $this->catalogOrEmpty($openApiSpecService),
            'servers' => $this->serverCatalog(),
        ]);
    }

    public function store(
        StoreUserRequest $request,
        ReplaceUserAccessAction $replaceUserAccessAction,
    ): RedirectResponse {
        $payload = $this->validatedUserPayload($request);

        $user = User::query()->create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
        ]);
        $user->syncRoles([$payload['role']]);
        $replaceUserAccessAction->handle($user, $payload['tags'], $payload['endpoints'], $payload['servers']);

        return to_route('admin.users.index')->with('status', 'User created.');
    }

    public function edit(User $user, OpenApiSpecService $openApiSpecService): Response
    {
        return Inertia::render('admin/users/form', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $this->firstRoleName($user),
                'grants' => [
                    'tags' => array_values($user->allowedTags->pluck('tag')->toArray()),
                    'endpoints' => $user->allowedEndpoints
                        ->map(fn ($endpoint): string => $endpoint->method->value.' '.$endpoint->path)
                        ->values()
                        ->all(),
                    'servers' => array_map(
                        static fn (int $id): string => (string) $id,
                        $user->allowedServers->modelKeys(),
                    ),
                ],
            ],
            'roles' => $this->allRoles(),
            'openapi' => $this->catalogOrEmpty($openApiSpecService, $user),
            'servers' => $this->serverCatalog($user),
        ]);
    }

    public function update(
        UpdateUserRequest $request,
        User $user,
        ReplaceUserAccessAction $replaceUserAccessAction,
    ): RedirectResponse {
        $payload = $this->validatedUserPayload($request);

        if ($this->wouldDropLastAdmin($user, $payload['role'])) {
            throw ValidationException::withMessages([
                'role' => __('Cannot remove the last administrator user.'),
            ]);
        }

        $user->fill([
            'name' => $payload['name'],
            'email' => $payload['email'],
        ]);

        if ($payload['password'] !== '') {
            $user->password = Hash::make($payload['password']);
        }

        $user->save();
        $user->syncRoles([$payload['role']]);
        $replaceUserAccessAction->handle($user, $payload['tags'], $payload['endpoints'], $payload['servers']);

        return to_route('admin.users.index')->with('status', 'User updated.');
    }

    public function destroy(User $user): RedirectResponse
    {
        $adminRole = $this->adminRole();
        if ($user->hasRole($adminRole) && User::role($adminRole)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => __('Cannot delete the last administrator user.'),
            ]);
        }

        $user->delete();

        return back()->with('status', 'User deleted.');
    }

    /**
     * @return array{
     *     name: string,
     *     email: string,
     *     role: string,
     *     password: string,
     *     tags: list<string>,
     *     endpoints: list<array{method: string, path: string}>,
     *     servers: list<int>
     * }
     */
    private function validatedUserPayload(StoreUserRequest|UpdateUserRequest $request): array
    {
        return [
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'role' => $request->string('role')->toString(),
            'password' => $request->string('password')->toString(),
            'tags' => $request->tagPayload(),
            'endpoints' => $request->endpointPayload(),
            'servers' => $request->serverPayload(),
        ];
    }

    /**
     * @return list<string>
     */
    private function allRoles(): array
    {
        $guard = config('auth.defaults.guard');
        if (! is_string($guard) || $guard === '') {
            $guard = 'web';
        }

        $roles = Role::query()
            ->where('guard_name', $guard)
            ->orderBy('name')
            ->pluck('name')
            ->all();

        $result = [];
        foreach ($roles as $name) {
            if (is_string($name)) {
                $result[] = $name;
            }
        }

        return array_values(array_unique($result));
    }

    private function firstRoleName(User $user): ?string
    {
        $role = $user->roles->pluck('name')->first();

        return is_string($role) ? $role : null;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function toUserPayloads(Collection $users): array
    {
        $rows = [];

        foreach ($users as $user) {
            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $this->firstRoleName($user),
                'roles' => array_values(array_map(
                    static fn (mixed $name): string => is_string($name) ? $name : '',
                    $user->roles->pluck('name')->all(),
                )),
                'grants' => [
                    'tags' => array_values(array_map(
                        static fn (mixed $tag): string => is_string($tag) ? $tag : '',
                        $user->allowedTags->pluck('tag')->all(),
                    )),
                    'endpoints' => $user->allowedEndpoints
                        ->map(fn ($endpoint): string => $endpoint->method->value.' '.$endpoint->path)
                        ->values()
                        ->all(),
                ],
            ];
        }

        return $rows;
    }

    private function adminRole(): string
    {
        $role = config('openapi.admin_role');

        return is_string($role) && $role !== '' ? $role : 'admin';
    }

    /**
     * @return array{
     *     tags: list<array{value: string, label: string}>,
     *     endpoints: list<array{value: string, label: string}>
     * }
     */
    private function serializeOpenApiCatalog(OpenApiSpecService $service): array
    {
        $spec = $service->fetchRaw();
        $tags = $service->extractTags($spec);
        $endpoints = $service->extractEndpoints($spec);

        $serializedTags = [];
        foreach (array_unique($tags) as $tag) {
            $serializedTags[] = [
                'value' => $tag,
                'label' => $tag,
            ];
        }

        $serializedEndpoints = [];
        foreach ($endpoints as ['method' => $method, 'path' => $path, 'label' => $label, 'summary' => $summary]) {
            $summary = is_string($summary) ? $summary : null;

            $serializedEndpoints[] = [
                'value' => $method.' '.$path,
                'label' => $summary !== null && $summary !== '' ? $label.' — '.$summary : $label,
            ];
        }

        return [
            'tags' => $serializedTags,
            'endpoints' => $serializedEndpoints,
        ];
    }

    /**
     * @param  list<array{value: string, label: string}>  $left
     * @param  list<array{value: string, label: string}>  $right
     * @return list<array{value: string, label: string}>
     */
    private function mergeCatalogOptions(array $left, array $right): array
    {
        $options = [];
        $seen = [];

        foreach ([$left, $right] as $collection) {
            foreach ($collection as $option) {
                $value = (string) $option['value'];
                if (isset($seen[$value])) {
                    continue;
                }

                $options[] = [
                    'value' => $value,
                    'label' => (string) $option['label'],
                ];
                $seen[$value] = true;
            }
        }

        return $options;
    }

    /**
     * @return array{tags: list<array{value: string, label: string}>, endpoints: list<array{value: string, label: string}>}
     */
    private function userGrantCatalog(?User $user): array
    {
        if (! $user instanceof User) {
            return ['tags' => [], 'endpoints' => []];
        }

        $tagOptions = [];
        $seenTags = [];
        foreach ($user->allowedTags->pluck('tag')->all() as $tag) {
            if (! is_string($tag) || isset($seenTags[$tag])) {
                continue;
            }

            $seenTags[$tag] = true;
            $tagOptions[] = [
                'value' => $tag,
                'label' => $tag,
            ];
        }

        $endpointOptions = [];
        foreach ($user->allowedEndpoints as $endpoint) {
            $endpointOptions[] = [
                'value' => $endpoint->method->value.' '.$endpoint->path,
                'label' => $endpoint->method->value.' '.$endpoint->path,
            ];
        }

        return [
            'tags' => $tagOptions,
            'endpoints' => $endpointOptions,
        ];
    }

    /**
     * OpenAPI UI catalog is optional for UX: when upstream metadata is
     * unavailable, render the form with empty grant options instead of failing
     * the whole page (grants are still validated server-side).
     *
     * @return array{tags: list<array{value:string,label:string}>, endpoints: list<array{value:string,label:string}>}
     */
    private function catalogOrEmpty(OpenApiSpecService $service, ?User $user = null): array
    {
        try {
            $catalog = $this->serializeOpenApiCatalog($service);
            $userCatalog = $this->userGrantCatalog($user);

            return [
                'tags' => $this->mergeCatalogOptions($catalog['tags'], $userCatalog['tags']),
                'endpoints' => $this->mergeCatalogOptions($catalog['endpoints'], $userCatalog['endpoints']),
            ];
        } catch (\Throwable $exception) {
            Log::warning('Unable to load OpenAPI catalog for user grants UI', [
                'exception' => $exception::class,
            ]);

            return $this->userGrantCatalog($user);
        }
    }

    /**
     * Active playground servers offered for per-user grants. When editing, the
     * user's currently-assigned servers are included even if since deactivated,
     * so an existing grant stays visible and removable.
     *
     * @return list<array{value: string, label: string}>
     */
    private function serverCatalog(?User $user = null): array
    {
        $assignedIds = $user instanceof User ? $user->allowedServers->modelKeys() : [];

        $servers = ScalarServer::query()
            ->where(function ($query) use ($assignedIds): void {
                $query->where('is_active', true);
                if ($assignedIds !== []) {
                    $query->orWhereIn('id', $assignedIds);
                }
            })
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'url', 'description']);

        $options = [];
        foreach ($servers as $server) {
            $label = $server->description !== null && $server->description !== ''
                ? $server->url.' — '.$server->description
                : $server->url;

            $options[] = ['value' => (string) $server->id, 'label' => $label];
        }

        return $options;
    }

    private function wouldDropLastAdmin(User $user, string $nextRole): bool
    {
        if ($user->hasRole($this->adminRole()) && $nextRole !== $this->adminRole()) {
            return User::role($this->adminRole())->count() <= 1;
        }

        return false;
    }

    /**
     * @param  Collection<int, User>  $users
     * @return list<array<string, mixed>>
     */
    private function serializeUsers(Collection $users): array
    {
        return $this->toUserPayloads($users);
    }
}
