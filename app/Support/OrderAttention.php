<?php

namespace App\Support;

/**
 * The orders a channel's tabs would otherwise lose. Marketplaces publish no list of their statuses, so a code
 * shows up first in an order: either the status map does not know it (stock and revenue are undecided), or it is
 * mapped but no tab lists it. Neither has a queue, so both go to the Needs attention tab, shown only while any exist.
 */
final class OrderAttention
{
    public const TAB = 'ATTENTION';

    public const NOT_MAPPED = 'not_mapped';

    public const NO_TAB = 'no_tab';

    /**
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $orders  the channel's orders, unfiltered
     * @param  list<string>  $tabStatuses  every status some tab lists
     * @param  list<string>  $mappedStatuses  every status the channel's status map knows, in any context
     * @return array<string, string>  status => self::NOT_MAPPED | self::NO_TAB
     */
    public static function reasons($orders, array $tabStatuses, array $mappedStatuses): array
    {
        $key = fn ($s) => strtolower(trim((string) $s));
        $inTab = array_flip(array_map($key, $tabStatuses));
        $mapped = array_flip(array_map($key, $mappedStatuses));

        $reasons = [];
        foreach ((clone $orders)->whereNotNull('status')->where('status', '!=', '')->distinct()->pluck('status') as $status) {
            $k = $key($status);
            if (! isset($mapped[$k])) {
                $reasons[(string) $status] = self::NOT_MAPPED;
            } elseif (! isset($inTab[$k])) {
                $reasons[(string) $status] = self::NO_TAB;
            }
        }

        return $reasons;
    }

    /** Puts the tab first, before All, only while it holds orders. */
    public static function prepend(array $tabs, array $counts, $orders, array $reasons): array
    {
        if ($reasons === []) {
            return [$tabs, $counts];
        }

        return [
            [self::TAB => 'Needs attention'] + $tabs,
            [self::TAB => (clone $orders)->whereIn('status', array_keys($reasons))->count()] + $counts,
        ];
    }
}
