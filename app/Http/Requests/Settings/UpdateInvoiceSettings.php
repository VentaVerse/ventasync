<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

class UpdateInvoiceSettings extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'prefix' => ['required', 'string', 'max:24', 'regex:/^[A-Za-z0-9\-\/_.]+$/'],
            'print_logo' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'prefix.regex' => 'Use letters, numbers and - / _ . only.',
        ];
    }
}
