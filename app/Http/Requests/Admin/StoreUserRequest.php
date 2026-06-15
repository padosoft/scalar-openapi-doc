<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;
use Stringable;

final class StoreUserRequest extends BaseUserRequest
{
    public function authorize(): bool
    {
        $adminRole = $this->adminRole();

        return (bool) ($this->user()?->hasRole($adminRole));
    }

    /**
     * @return array<string, list<string|Stringable>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'string', 'max:255', Rule::in($this->allowedRoles())],
            ...$this->grantRules(),
        ];
    }
}
