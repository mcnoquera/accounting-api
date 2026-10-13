<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AccountRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('account')?->id;
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'code' => [$required, 'string', 'max:20', 'regex:/^[A-Za-z0-9.\-]+$/', Rule::unique('accounts', 'code')->ignore($id)],
            'name' => [$required, 'string', 'max:255'],
            'type' => [$required, Rule::enum(AccountType::class)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
