<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the per-vendor `email_po_enabled` toggle.
 *
 * The "Allow sending POs to this vendor via email" checkbox was redundant:
 * the operator already decides per-PO by clicking "Email to Vendor" on an
 * approved PO. The button now shows whenever the vendor has an email address,
 * with no pre-enable flag. This drops the orphaned column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vendors', 'email_po_enabled')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropColumn('email_po_enabled');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vendors', 'email_po_enabled')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->boolean('email_po_enabled')->default(false)->after('payment_terms');
            });
        }
    }
};
