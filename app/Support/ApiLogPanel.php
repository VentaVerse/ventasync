<?php

namespace App\Support;

use Closure;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The API log's read side (#200): every channel's Logs panel narrows and
 * pages the same way, so the query params - log_show=errors, log_q=,
 * logPage= - are one vocabulary. withQueryString() is what keeps the
 * active ?tab= (and a multi-store ?store=) on every pager link, so
 * refreshing or paging never throws the operator off the panel.
 */
class ApiLogPanel
{
    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $query
     * @param  string  $pathColumn  the endpoint/path column to search
     * @param  Closure  $errorClause  fn($query) => narrows to failed calls
     */
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

    /** The nightly retention this panel should state, in days. */
    public static function keepDays(): int
    {
        return (int) config('logs.default_keep_days', 30);
    }
}
