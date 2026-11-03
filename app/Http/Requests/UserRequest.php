<?php

namespace App\Http\Requests;

use App\Enums\Role;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    /** Permission is checked before validation so non-admins get 403, not field errors. */
    public function authorize(): bool
    {
        return Gate::allows('manage-users');
    }

    public function rules(): array
    {
        $id = $this->route('user')?->id;
        $creating = $this->isMethod('post');
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:255'],
            'email' => [$required, 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'role' => [$required, Rule::enum(Role::class)],
            'is_active' => ['sometimes', 'boolean'],
            'password' => [$creating ? 'required' : 'sometimes', 'string', Password::min(12)],
        ];
    }
}
