<?php

namespace App\Plans;

trait CountsAsStore
{
    public static function bootCountsAsStore(): void
    {
        static::creating(function () {
            if ($full = Quota::refusal('stores')) {
                throw new PlanLimitReached($full);
            }
        });
    }
}
