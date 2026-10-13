<?php

namespace App\Http\Requests;

use App\Enums\Severity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditFindingRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'journal_entry_id' => ['nullable', 'integer', 'exists:journal_entries,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'severity' => ['required', Rule::enum(Severity::class)],
        ];
    }
}
