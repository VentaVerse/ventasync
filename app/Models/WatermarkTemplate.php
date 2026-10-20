<?php

namespace App\Models;

use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Database\Eloquent\Model;

class WatermarkTemplate extends Model
{
    protected $table = 'ch_watermark_templates';

    protected $guarded = [];

    protected $casts = [
        'size_percent' => 'float',
        'offset_x_percent' => 'float',
        'offset_y_percent' => 'float',
        'opacity' => 'float',
    ];

    public static function forStore(string $channel, int $storeId)
    {
        return static::ofStore($channel, $storeId)->orderBy('name')->get(['id', 'name', 'image_path']);
    }

    public static function ofStore(string $channel, int $storeId)
    {
        return static::query()->where('channel', $channel)->where('store_id', $storeId);
    }

    public static function resolve(?int $listingTemplateId, string $channel, int $storeId): ?self
    {
        return $listingTemplateId !== null && $listingTemplateId > 0 ? static::ofStore($channel, $storeId)->find($listingTemplateId) : null;
    }

    public static function idOrNull(mixed $id, string $channel, int $storeId): ?int
    {
        $id = (int) $id;

        return ($id > 0 && static::ofStore($channel, $storeId)->whereKey($id)->exists()) ? $id : null;
    }

    public function markPath(): string
    {
        return \Illuminate\Support\Facades\Storage::disk('public')->path(ltrim((string) $this->image_path, '/'));
    }

    public function transparencyPercent(): int
    {
        return (int) round((1 - (float) ($this->opacity ?? 1)) * 100);
    }

    public static function opacityFor(float $transparencyPercent): float
    {
        return round(1 - max(0.0, min((float) self::MAX_TRANSPARENCY, $transparencyPercent)) / 100, 3);
    }

    public const MAX_TRANSPARENCY = 90;

    public function fingerprint(): string
    {
        $mark = $this->markPath();

        return substr(sha1(implode('|', [
            (string) $this->image_path,
            (string) $this->position,
            (string) $this->size_percent,
            (string) $this->offset_x_percent,
            (string) $this->offset_y_percent,
            (string) $this->opacity,
            is_file($mark) ? (string) filemtime($mark) : '0',
        ])), 0, 8);
    }

    public function markUrl(): string
    {
        return ProductImages::url((string) $this->image_path);
    }

    public function stamp(string $imagePath): ?string
    {
        return app(Watermarker::class)->stamp(
            $imagePath,
            $this->markPath(),
            (string) $this->position,
            (float) $this->size_percent,
            (float) $this->opacity,
            (float) $this->offset_x_percent,
            (float) $this->offset_y_percent,
        );
    }
}
