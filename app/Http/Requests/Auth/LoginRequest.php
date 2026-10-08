<?php

namespace App\Http\Requests\Auth;

use App\Support\Auth\AccountLock;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username' => ['required','string'],
            'password' => ['required', 'string'],
        ];
    }

    public function authenticate(): void
    {
        $account = \App\Models\User::where('username', (string) $this->input('username'))->first();
        if ($account && AccountLock::isLocked($account)) {
            throw ValidationException::withMessages(['username' => AccountLock::message($account)]);
        }

        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('username','password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());
            if ($account) {
                AccountLock::failed($account);
            }

            throw ValidationException::withMessages([
                'username' => $account && AccountLock::isLocked($account->fresh()) ? AccountLock::message($account->fresh()) : trans('auth.failed'),
            ]);
        }

        AccountLock::clear(Auth::user());
        RateLimiter::clear($this->throttleKey());
    }

    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'username' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('username')).'|'.$this->ip());
    }
}
