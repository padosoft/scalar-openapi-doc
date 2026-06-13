<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\UserAllowedEndpoint;
use App\Models\UserAllowedTag;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Replaces a user's granted tags and endpoints in a single transaction:
 * delete the existing rows, then bulk-insert the new set (chunked). Reproduces
 * the "clear and re-insert" semantics atomically (ADR-07). No array_merge in
 * loops — rows are built once and inserted in chunks.
 */
final class ReplaceUserAccessAction
{
    /** Max rows per bulk insert to keep statements within driver limits. */
    private const CHUNK = 500;

    /**
     * @param  list<string>  $tags  OpenAPI tag names
     * @param  list<array{method: string, path: string}>  $endpoints
     */
    public function handle(User $user, array $tags, array $endpoints): void
    {
        DB::transaction(function () use ($user, $tags, $endpoints): void {
            $user->allowedTags()->delete();
            $user->allowedEndpoints()->delete();

            $now = Carbon::now();

            $this->insertChunked(UserAllowedTag::class, $this->tagRows($user->id, $tags, $now));
            $this->insertChunked(UserAllowedEndpoint::class, $this->endpointRows($user->id, $endpoints, $now));
        });
    }

    /**
     * @param  list<string>  $tags
     * @return list<array<string, mixed>>
     */
    private function tagRows(int $userId, array $tags, Carbon $now): array
    {
        $unique = array_values(array_unique(array_filter($tags, static fn (string $t): bool => $t !== '')));

        return array_map(static fn (string $tag): array => [
            'user_id' => $userId,
            'tag' => $tag,
            'created_at' => $now,
            'updated_at' => $now,
        ], $unique);
    }

    /**
     * @param  list<array{method: string, path: string}>  $endpoints
     * @return list<array<string, mixed>>
     */
    private function endpointRows(int $userId, array $endpoints, Carbon $now): array
    {
        $rows = [];
        $seen = [];

        foreach ($endpoints as $endpoint) {
            $method = strtoupper(trim($endpoint['method']));
            $path = $endpoint['path'];
            $key = $method.' '.$path;

            // De-duplicate so the chunked insert can't violate the unique index.
            if ($method === '' || $path === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $rows[] = [
                'user_id' => $userId,
                'method' => $method,
                'path' => $path,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }

    /**
     * @param  class-string<UserAllowedTag|UserAllowedEndpoint>  $model
     * @param  list<array<string, mixed>>  $rows
     */
    private function insertChunked(string $model, array $rows): void
    {
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $model::insert($chunk);
        }
    }
}
