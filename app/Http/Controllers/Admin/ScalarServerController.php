<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerRequest;
use App\Models\ScalarServer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ScalarServerController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('admin/servers/index', [
            'servers' => ScalarServer::query()
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(
                    static fn (ScalarServer $server): array => [
                        'id' => $server->id,
                        'url' => $server->url,
                        'description' => $server->description,
                        'sort_order' => $server->sort_order,
                        'is_active' => $server->is_active,
                    ],
                )
                ->all(),
            'csrfToken' => $request->session()->token(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('admin/servers/form', [
            'server' => null,
        ]);
    }

    public function store(ServerRequest $request): RedirectResponse
    {
        ScalarServer::query()->create($request->validated());

        return to_route('admin.servers.index')->with('status', 'Server created.');
    }

    public function edit(ScalarServer $server): Response
    {
        return Inertia::render('admin/servers/form', [
            'server' => [
                'id' => $server->id,
                'url' => $server->url,
                'description' => $server->description,
                'sort_order' => $server->sort_order,
                'is_active' => $server->is_active,
            ],
        ]);
    }

    public function update(ServerRequest $request, ScalarServer $server): RedirectResponse
    {
        $server->fill($request->validated());
        $server->save();

        return to_route('admin.servers.index')->with('status', 'Server updated.');
    }

    public function destroy(ScalarServer $server): RedirectResponse
    {
        $server->delete();

        return back()->with('status', 'Server deleted.');
    }
}
