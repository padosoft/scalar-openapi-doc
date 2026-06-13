<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A playground server entry injected into the OpenAPI `servers` array (the
 * environment dropdown in Scalar).
 *
 * @property int $id
 * @property string $url
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_active
 */
class ScalarServer extends Model
{
    /** @var list<string> */
    protected $fillable = ['url', 'description', 'sort_order', 'is_active'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
