<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Validation\Rule;
use Stringable;

final class UpdateUserRequest extends BaseUserRequest
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
        $userId = $this->route('user') instanceof User ? $this->route('user')->id : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($userId),
            ],
            'password' => ['nullable', 'string', 'min:8', 'max:255'],
            'role' => ['required', 'string', 'max:255', Rule::in($this->allowedRoles())],
            ...$this->grantRules(),
        ];
    }
}
