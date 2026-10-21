<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const CHANNELS = ['shopee', 'lazada', 'tiktok', 'woocommerce', 'ventacart'];

    public function up(): void
    {
        foreach (self::CHANNELS as $channel) {
            $groups = "ch_{$channel}_product_groups";
            if (! Schema::hasTable($groups) || Schema::hasColumn($groups, 'listing_template_id')) {
                continue;
            }
            Schema::table($groups, fn (Blueprint $t) => $t->unsignedBigInteger('listing_template_id')->nullable()->after('name'));

            $templates = "ch_{$channel}_listing_templates";
            if (! Schema::hasTable($templates)) {
                continue;
            }
            foreach (DB::table($groups)->get(['id', 'store_id', 'name']) as $group) {
                $templateId = DB::table($templates)->where('store_id', $group->store_id)->where('name', $group->name)->orderBy('id')->value('id');
                if ($templateId !== null) {
                    DB::table($groups)->where('id', $group->id)->update(['listing_template_id' => $templateId]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::CHANNELS as $channel) {
            $groups = "ch_{$channel}_product_groups";
            if (Schema::hasTable($groups) && Schema::hasColumn($groups, 'listing_template_id')) {
                Schema::table($groups, fn (Blueprint $t) => $t->dropColumn('listing_template_id'));
            }
        }
    }
};
