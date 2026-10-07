<?php

namespace App\Models;

use App\Services\Media\ProductVideoFile;
use Illuminate\Database\Eloquent\Model;

class ProductVideo extends Model
{
    protected $guarded = [];

    protected $casts = [
        'bytes' => 'integer',
        'duration_ms' => 'integer',
        'ai_generated' => 'boolean',
    ];

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
