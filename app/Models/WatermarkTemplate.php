<?php

namespace App\Models;

use App\Services\Media\Watermarker;
use App\Support\Catalog\ProductImages;
use Illuminate\Database\Eloquent\Model;

class WatermarkTemplate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'size_percent' => 'float',
        'offset_x_percent' => 'float',
        'offset_y_percent' => 'float',
        'opacity' => 'float',
    ];

    public static function forStore(string $integration, int $storeId)
    {
        return static::ofStore($integration, $storeId)->orderBy('name')->get(['id', 'name', 'image_path']);
    }

    public static function ofStore(string $integration, int $storeId)
    {
        return static::query()->where('integration', $integration)->where('store_id', $storeId);
    }

    public static function nameOf(?int $id, string $integration, int $storeId): ?string
    {
        return ($id === null || $id <= 0)
            ? null
            : static::ofStore($integration, $storeId)->whereKey($id)->value('name');
    }

    public static function resolve(?int $listingTemplateId, ?int $groupTemplateId, string $integration, int $storeId): ?self
    {
        foreach ([$listingTemplateId, $groupTemplateId] as $id) {
            if ($id !== null && $id > 0 && ($row = static::ofStore($integration, $storeId)->find($id))) {
                return $row;
            }
        }

        return null;
    }

    public static function idOrNull(mixed $id, string $integration, int $storeId): ?int
    {
        $id = (int) $id;

        return ($id > 0 && static::ofStore($integration, $storeId)->whereKey($id)->exists()) ? $id : null;
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
