<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An OpenAPI tag granted to a user (visibility by tag, UNION semantics).
 *
 * @property int $id
 * @property int $user_id
 * @property string $tag
 */
class UserAllowedTag extends Model
{
    /** @var list<string> */
    protected $fillable = ['user_id', 'tag'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
