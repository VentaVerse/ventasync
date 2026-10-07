<?php

namespace App\Models\Concerns;

use App\Support\LogRetention;

trait PrunedByLogLevel
{
    protected static function bootPrunedByLogLevel(): void
    {
        static::saved(function ($log) {
            if ($log->exists && LogRetention::shouldForget($log)) {
                $log->delete();
            }
        });
    }
}
