<?php

namespace App\Plans;

use App\Models\ApiClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Counters
{
    public static function products(): int
    {
        return (int) DB::table(config('catalog.prefix') . 'product')->count();
    }

    public static function users(): int
    {
        return User::query()->count();
    }

    public static function stores(): int
    {
        $total = 0;

        foreach (self::storeModels() as $class) {
            $table = (new $class())->getTable();
            if (Schema::hasTable($table)) {
                $total += (int) DB::table($table)->count();
            }
        }

        return $total;
    }

    public static function apiApps(): int
    {
        return ApiClient::query()->count();
    }

    public static function ordersThisMonth(): int
    {
        return (int) DB::table(config('catalog.prefix') . 'order')
            ->where('date_added', '>=', now()->startOfMonth())
            ->count();
    }

    public static function storeModels(): array
    {
        $models = [];

        foreach (glob(base_path('extensions/*/Models/*.php')) ?: [] as $file) {
            $class = 'Extensions\\' . basename(dirname($file, 2)) . '\\Models\\' . basename($file, '.php');
            if (class_exists($class) && in_array(CountsAsStore::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }
}
