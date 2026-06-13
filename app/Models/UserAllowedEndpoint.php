<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HttpVerb;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single OpenAPI operation granted to a user, identified by (method, path).
 *
 * @property int $id
 * @property int $user_id
 * @property HttpVerb $method uppercase HTTP verb
 * @property string $path OpenAPI path template
 */
class UserAllowedEndpoint extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'method', 'path'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['method' => HttpVerb::class];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
