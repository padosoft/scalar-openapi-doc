<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single OpenAPI operation granted to a user, identified by (method, path).
 *
 * @property int $id
 * @property int $user_id
 * @property string $method uppercase HTTP verb
 * @property string $path OpenAPI path template
 */
class UserAllowedEndpoint extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'method', 'path'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
