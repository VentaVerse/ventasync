<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A run that is not a scheduled job: sending a whole product group from the
 * Listings page walks the same steps. It has no job, and names what it walks
 * in subject ("group:12").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('scheduled_job_id')->nullable()->change();
            if (! Schema::hasColumn('automation_runs', 'subject')) {
                $table->string('subject', 64)->nullable()->after('store_id');
                $table->index(['integration', 'store_id', 'subject', 'status']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            if (Schema::hasColumn('automation_runs', 'subject')) {
                $table->dropIndex(['integration', 'store_id', 'subject', 'status']);
                $table->dropColumn('subject');
            }
        });
    }
};
