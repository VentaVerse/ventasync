<?php

namespace App\Http\Requests\Settings;

use App\Models\InvoiceSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTaxRegistration extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'tin' => preg_replace('/\D/', '', (string) $this->input('tin')),
            'branch_code' => preg_replace('/\D/', '', (string) $this->input('branch_code')),
        ]);
    }

    public function rules(): array
    {
        return [
            'tax_status' => ['required', Rule::in([InvoiceSettings::VAT, InvoiceSettings::NON_VAT])],
            'tin' => ['nullable', 'digits:9'],
            'branch_code' => ['nullable', 'digits:5'],
            'business_style' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'tin.digits' => 'A TIN has 9 digits.',
            'branch_code.digits' => 'A branch code has 5 digits.',
        ];
    }
}
