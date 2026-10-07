<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ChannelProductStatus
{
    public static function write(
        string $pivotTable,
        string $groupColumn,
        ?array $groupIds,
        int $productId,
        array $attributes
    ): int {
        if ($groupIds !== null && empty($groupIds)) {
            return 0;
        }

        if (array_key_exists('push_error', $attributes) && is_string($attributes['push_error'])) {
            $attributes['push_error'] = Str::limit($attributes['push_error'], 480);
        }

        $query = DB::table($pivotTable)->where('product_id', $productId);

        if ($groupIds !== null) {
            $query->whereIn($groupColumn, $groupIds);
        }

        return $query->update($attributes);
    }
}
