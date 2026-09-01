<?php

namespace App\Models\Concerns;

use App\Support\LogRetention;

/**
 * A sync-run log that removes itself when the operator has said runs like
 * it are not worth keeping (Settings, Website: "Record sync runs").
 *
 * Hooked on `saved` rather than refused on `creating`: the row is always
 * written when the run starts, so a sync that crashes mid-flight still
 * leaves its evidence, and the sync services keep working with a real model
 * exactly as they do today. It is the ENDING that decides. Deleting here
 * leaves the in-memory attributes intact, which is all the callers read
 * after a sync returns.
 */
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
