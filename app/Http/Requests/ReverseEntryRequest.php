<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReverseEntryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:200'],
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
