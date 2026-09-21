<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A NEW STORE STARTS IN THE SANDBOX.
 *
 * A store is created before anyone has typed a key into it, and the first
 * thing an operator does is try it. Starting in production means those first
 * attempts are aimed at a real shop. Shopee already started this way; this
 * brings the other two into line.
 *
 * Only the default for rows made from here on. Every store that exists keeps
 * the environment it is in.
 */
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
