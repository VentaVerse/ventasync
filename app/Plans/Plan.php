<?php

namespace App\Plans;

final class Plan
{
    public static function hosted(): bool
    {
        foreach ((array) config('plans.extensions') as $value) {
            if (self::present($value)) {
                return true;
            }
        }

        foreach ((array) config('plans.limits') as $value) {
            if (self::present($value)) {
                return true;
            }
        }

        return false;
    }

    public static function allowsExtension(string $id): bool
    {
        if (! self::hosted()) {
            return true;
        }

        return self::on(config('plans.extensions.' . $id));
    }

    public static function extensions(): ?array
    {
        if (! self::hosted()) {
            return null;
        }

        return array_keys(array_filter((array) config('plans.extensions'), fn ($value) => self::on($value)));
    }

    public static function limit(string $name): ?int
    {
        $value = config('plans.limits.' . $name);

        if (! self::present($value) || ! is_numeric($value)) {
            return null;
        }

        return max(0, (int) $value);
    }

    public static function allowsUploads(): bool
    {
        return ! self::hosted();
    }

    private static function present(mixed $value): bool
    {
        return $value !== null && trim((string) $value) !== '';
    }

    private static function on(mixed $value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }
}
