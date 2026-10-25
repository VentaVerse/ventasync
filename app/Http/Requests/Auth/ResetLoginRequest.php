<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use App\Support\Auth\ResetLink;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ResetLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ResetLink::valid($this, $this->linkUser());
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->view('auth.reset-login', ['expired' => true], 403));
    }

    private function linkUser(): User
    {
        return request()->route('user');
    }

    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:255', Rule::unique('users', 'username')->ignore($this->linkUser())],
            'password' => ['required', 'confirmed', Password::min(8)],
        ];
    }
}
