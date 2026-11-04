<?php

namespace App\Http\Requests;

use App\Enums\AccountType;
use App\Enums\CashKind;
use App\Enums\ControlType;
use App\Enums\TaxRole;
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
            'parent_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')],
            'tax_role' => ['nullable', Rule::enum(TaxRole::class)],
            'control_type' => ['nullable', Rule::enum(ControlType::class), function (string $attr, mixed $value, \Closure $fail) {
                $type = $this->input('type') ?? $this->route('account')?->type?->value;
                $expected = $value === ControlType::Receivable->value ? 'asset' : 'liability';
                if ($value !== null && $type !== $expected) {
                    $fail("A {$value} control account must be a{$this->article($expected)} {$expected} account.");
                }
            }],
            'is_cash' => ['sometimes', 'boolean', function (string $attr, mixed $value, \Closure $fail) {
                $type = $this->input('type') ?? $this->route('account')?->type?->value;
                if ($value && $type !== 'asset') {
                    $fail('Only asset accounts can be cash or bank accounts.');
                }
            }],
            'cash_kind' => ['nullable', Rule::enum(CashKind::class), function (string $attr, mixed $value, \Closure $fail) {
                $type = $this->input('type') ?? $this->route('account')?->type?->value;
                if ($value && $type !== 'asset') {
                    $fail('Only asset accounts can be cash, bank or e-wallet accounts.');
                }
            }],
            'provider' => ['nullable', 'string', 'max:100'],
            'account_number_last4' => ['nullable', 'regex:/^\d{4}$/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Keeps is_cash and cash_kind consistent: a kind makes it a cash account; no kind clears it. */
    protected function passedValidation(): void
    {
        if ($this->has('cash_kind')) {
            $this->merge(['is_cash' => $this->input('cash_kind') !== null]);
        } elseif ($this->boolean('is_cash')) {
            $this->merge(['cash_kind' => $this->route('account')?->cash_kind?->value ?? 'cash']);
        } elseif ($this->has('is_cash')) {
            $this->merge(['cash_kind' => null, 'provider' => null, 'account_number_last4' => null]);
        }
    }

    public function validated($key = null, $default = null)
    {
        $data = parent::validated();
        foreach (['is_cash', 'cash_kind', 'provider', 'account_number_last4'] as $k) {
            if ($this->has($k)) {
                $data[$k] = $this->input($k);
            }
        }

        return $key === null ? $data : ($data[$key] ?? $default);
    }

    private function article(string $word): string
    {
        return in_array($word[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'n' : '';
    }
}
