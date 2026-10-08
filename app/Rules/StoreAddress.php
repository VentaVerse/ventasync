<?php

namespace App\Rules;

use App\Support\Net\StoreRequest;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class StoreAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $url = trim((string) $value);
        if ($url === '') {
            return;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://' . $url;
        }

        if ($refusal = StoreRequest::refusal($url)) {
            $fail($refusal);
        }
    }
}
