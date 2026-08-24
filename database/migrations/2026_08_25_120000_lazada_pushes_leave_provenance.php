<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Lazada half of push provenance (#104, ported from Shopee): what the
 * last push actually sent and where its settings came from - the form's
 * own listing, or a named group - so "whichever you push wins" is an
 * auditable fact instead of something held in the head.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->timestamp('last_pushed_at')->nullable()->after('last_sync_error_message');
            $table->string('last_push_source', 120)->nullable()->after('last_pushed_at');
            $table->json('last_push_settings')->nullable()->after('last_push_source');
        });
    }

    public function down(): void
    {
        Schema::table('lazada_products', function (Blueprint $table) {
            $table->dropColumn(['last_pushed_at', 'last_push_source', 'last_push_settings']);
        });
    }
};
