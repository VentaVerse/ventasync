<?php

namespace App\Channels\Listings\Templates;

use Illuminate\Database\Eloquent\Model;

final class TemplateField
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        private readonly \Closure $read,
        private readonly \Closure $write,
        private readonly \Closure $show,
        private readonly bool $ordered = false,
    ) {
    }

    public static function column(string $key, string $label, string $column, ?\Closure $show = null): self
    {
        return new self(
            $key,
            $label,
            fn (Model $m) => self::blank($m->{$column}) ? null : $m->{$column},
            function (Model $m, $value) use ($column) {
                $m->{$column} = $value;
            },
            $show ?? fn ($v) => (string) $v,
        );
    }

    public static function attribute(string $key, string $label): self
    {
        return new self($key, $label, fn () => null, fn () => null, fn ($v) => is_array($v) ? implode(', ', array_map('strval', $v)) : (string) $v);
    }

    public static function priceRule(): self
    {
        return new self(
            'price_rule',
            'Price rule',
            fn (Model $m) => $m->markup_percent === null && $m->markup_fixed === null
                ? null
                : [self::number($m->markup_percent), self::number($m->markup_fixed)],
            function (Model $m, $value) {
                [$m->markup_percent, $m->markup_fixed] = $value;
            },
            function ($v) {
                $parts = [];
                if ((float) ($v[0] ?? 0) != 0) {
                    $parts[] = '+' . rtrim(rtrim(number_format((float) $v[0], 2), '0'), '.') . '%';
                }
                if ((float) ($v[1] ?? 0) != 0) {
                    $parts[] = '+₱' . number_format((float) $v[1], 2);
                }

                return $parts === [] ? 'No markup' : implode(' ', $parts);
            },
            true,
        );
    }

    public static function watermark(): self
    {
        return self::column('watermark', 'Watermark', 'watermark_template_id',
            fn ($v) => (string) (\App\Models\WatermarkTemplate::query()->whereKey((int) $v)->value('name') ?? 'Watermark #' . $v));
    }

    public function read(Model $listing): mixed
    {
        return ($this->read)($listing);
    }

    public function write(Model $listing, mixed $value): void
    {
        ($this->write)($listing, $value);
    }

    public function show(mixed $value): string
    {
        return ($this->show)($value);
    }

    public function same(mixed $a, mixed $b): bool
    {
        if (is_array($a) || is_array($b)) {
            $a = array_map(fn ($v) => is_numeric($v) ? (string) (float) $v : (string) $v, (array) $a);
            $b = array_map(fn ($v) => is_numeric($v) ? (string) (float) $v : (string) $v, (array) $b);
            if (! $this->ordered) {
                sort($a);
                sort($b);
            }

            return $a === $b;
        }

        return is_numeric($a) && is_numeric($b) ? (float) $a == (float) $b : trim((string) $a) === trim((string) $b);
    }

    public static function blank(mixed $value): bool
    {
        if (is_array($value)) {
            return $value === [];
        }

        return $value === null || trim((string) $value) === '' || (is_numeric($value) && (float) $value == 0);
    }

    private static function number(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
