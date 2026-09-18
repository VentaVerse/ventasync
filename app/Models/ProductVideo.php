<?php

namespace App\Models;

use App\Services\Media\ProductVideoFile;
use Illuminate\Database\Eloquent\Model;

/**
 * The one video a catalog product carries. Its file lives under video/ on
 * the public disk; this row is what the product form and every store push
 * read.
 */
class ProductVideo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bytes' => 'integer',
        'duration_ms' => 'integer',
        'ai_generated' => 'boolean',
    ];

    /** What the card shows: the file, how long it runs, how heavy it is. */
    public function summary(): array
    {
        return [
            'path' => (string) $this->path,
            'url' => \App\Services\Media\ImageCache::publicUrl((string) $this->path),
            'name' => (string) $this->original_name,
            'size' => ProductVideoFile::megabytes((int) $this->bytes),
            'length' => $this->duration_ms !== null ? ProductVideoFile::seconds($this->duration_ms / 1000) : null,
            'aiGenerated' => (bool) $this->ai_generated,
        ];
    }
}
