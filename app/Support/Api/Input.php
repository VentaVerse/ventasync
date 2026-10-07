<?php

namespace App\Support\Api;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;

final class Input
{
    public function __construct(private readonly array $data)
    {
    }

    public static function from(Request $request): self
    {
        return new self($request->all());
    }

    public function all(): array
    {
        return $this->data;
    }

    public function validate(array $rules, array $messages = []): array
    {
        return Validator::make($this->data, $rules, $messages)->validate();
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        return $key === null ? $this->data : data_get($this->data, $key, $default);
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }

    public function integer(string $key, int $default = 0): int
    {
        return intval($this->input($key, $default));
    }

    public function boolean(?string $key = null, bool $default = false): bool
    {
        return filter_var($this->input($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function has(string|array $keys): bool
    {
        return Arr::has($this->data, $keys);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && (is_bool($value) || is_array($value) || trim((string) $value) !== '');
    }

    public function only(string|array $keys): array
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        $out = [];
        foreach ($keys as $key) {
            if (Arr::has($this->data, $key)) {
                data_set($out, $key, data_get($this->data, $key));
            }
        }

        return $out;
    }
}
