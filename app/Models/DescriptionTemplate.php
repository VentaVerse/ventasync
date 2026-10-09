<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DescriptionTemplate extends Model
{
    protected $table = 'ch_description_templates';

    protected $guarded = [];

    public static function forStore(string $channel, int $storeId)
    {
        return static::query()
            ->where('channel', $channel)
            ->where('store_id', $storeId)
            ->orderBy('name')
            ->get(['id', 'name', 'body']);
    }

    public static function bodyOf(?int $id, string $channel, int $storeId): string
    {
        if ($id === null || $id <= 0) {
            return '';
        }
        $row = static::query()
            ->where('id', $id)->where('channel', $channel)->where('store_id', $storeId)
            ->first(['body']);

        return trim((string) ($row->body ?? ''));
    }
}
