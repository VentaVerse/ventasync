<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['lazada_settings', 'tiktok_settings'];

    public function up(): void
    {
        $this->setDefault('sandbox');
    }

    public function down(): void
    {
        $this->setDefault('live');
    }

    private function setDefault(string $mode): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'mode')) {
                continue;
            }
            Schema::table($table, function (Blueprint $t) use ($mode) {
                $t->string('mode', 16)->default($mode)->change();
            });
        }
    }
};
