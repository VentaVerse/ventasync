<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DescriptionTemplate extends Model
{
    protected $guarded = [];

    public static function forStore(string $integration, int $storeId)
    {
        return static::query()
            ->where('integration', $integration)
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'body']);
    }

    public static function bodyOf(?int $id, string $integration, int $storeId): string
    {
        if ($id === null || $id <= 0) {
            return '';
        }
        $row = static::query()
            ->where('id', $id)->where('integration', $integration)->where('store_id', $storeId)
            ->first(['body']);

        return trim((string) ($row->body ?? ''));
    }
}
