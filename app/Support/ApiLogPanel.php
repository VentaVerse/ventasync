<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ApiLogPanel
{
    public static function paginate($query, string $pathColumn, Closure $errorClause): LengthAwarePaginator
    {
        if (request('log_show') === 'errors') {
            $errorClause($query);
        }

        $q = trim((string) request('log_q', ''));
        if ($q !== '') {
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where($pathColumn, 'like', "%{$escaped}%");
        }

        return $query->orderByDesc('id')->paginate(50, ['*'], 'logPage')->withQueryString();
    }

    public static function keepDays(): int
    {
        return (int) config('logs.default_keep_days', 30);
    }
}
