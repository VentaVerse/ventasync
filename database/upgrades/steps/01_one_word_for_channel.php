<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function renames(): array
    {
        return [
            ['scheduled_jobs', 'integration', 'extension'],
            ['automation_runs', 'integration', 'extension'],
            ['description_templates', 'integration', 'channel'],
            ['watermark_templates', 'integration', 'channel'],
            ['order_fetch_runs', 'integration', 'channel'],
            ['marketplace_reviews', 'platform', 'channel'],
            ['marketplace_reviews', 'platform_review_id', 'channel_review_id'],
            ['marketplace_reviews', 'platform_item_id', 'channel_item_id'],
            ['marketplace_reviews', 'platform_order_id', 'channel_order_id'],
            [config('catalog.prefix') . 'order', 'marketplace_source', 'channel'],
        ];
    }

    public function up(): void
    {
        foreach ($this->renames() as [$table, $from, $to]) {
            $this->rename($table, $from, $to);
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->renames()) as [$table, $from, $to]) {
            $this->rename($table, $to, $from);
        }
    }

    private function rename(string $table, string $from, string $to): void
    {
        if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
            \App\Support\Database\ColumnRename::run($table, $from, $to);
        }
    }
};
