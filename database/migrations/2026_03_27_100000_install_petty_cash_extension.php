<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('petty_cash_categories')) {
            Schema::create('petty_cash_categories', function (Blueprint $table) {
                $table->id();
                $table->string('name', 128);
                $table->integer('sort_order')->default(0);
                $table->boolean('status')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('petty_cash_transactions')) {
            Schema::create('petty_cash_transactions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->enum('type', ['credit', 'expense']);
                $table->decimal('amount', 12, 2);
                $table->string('category', 128)->nullable();
                $table->text('description')->nullable();
                $table->date('transaction_date');
                $table->unsignedBigInteger('created_by');
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->foreign('created_by')->references('id')->on('users');
                $table->index(['user_id', 'transaction_date']);
            });
        }

        if (!Schema::hasTable('petty_cash_user_roles')) {
            Schema::create('petty_cash_user_roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
                $table->enum('role', ['admin', 'staff'])->default('staff');
                $table->timestamps();
            });
        }

        if (DB::table('petty_cash_categories')->count() === 0) {
            $now = now();
            DB::table('petty_cash_categories')->insert([
                ['name' => 'Gas/Fuel',         'sort_order' => 1, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Shipping/Courier', 'sort_order' => 2, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Tools & Supplies', 'sort_order' => 3, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Office Supplies',  'sort_order' => 4, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Food/Meals',       'sort_order' => 5, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Transportation',   'sort_order' => 6, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name' => 'Miscellaneous',    'sort_order' => 7, 'status' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }

        if (Schema::hasTable('extensions') && file_exists(base_path('extensions/pettycash/extension.json')) && !DB::table('extensions')->where('id', 'pettycash')->exists()) {
            DB::table('extensions')->insert([
                'id'          => 'pettycash',
                'name'        => 'Petty Cash',
                'version'     => '1.1.0',
                'description' => 'Staff petty cash tracking: credits and expense logging',
                'author'      => 'VentaSync',
                'enabled'     => 0,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }

        $permissions = [
            ['key' => 'view_petty_cash',              'label' => 'Petty Cash',              'description' => 'See the Petty Cash nav group'],
            ['key' => 'view_petty_cash_transactions',  'label' => 'Petty Cash Transactions',  'description' => 'View and manage petty cash transactions'],
            ['key' => 'manage_petty_cash_settings',    'label' => 'Petty Cash Settings',      'description' => 'Access petty cash settings and role management'],
        ];

        foreach ($permissions as $p) {
            if (!DB::table('permissions')->where('key', $p['key'])->exists()) {
                DB::table('permissions')->insert($p);
            }
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if ($adminGroupId) {
            foreach (['view_petty_cash', 'view_petty_cash_transactions', 'manage_petty_cash_settings'] as $key) {
                $permId = DB::table('permissions')->where('key', $key)->value('id');
                if ($permId) {
                    DB::table('user_group_permissions')->updateOrInsert(
                        ['user_group_id' => $adminGroupId, 'permission_id' => $permId]
                    );
                }
            }
        }

        $oldKeys = ['manage_petty_cash', 'view_own_petty_cash', 'pettycash_add_credits', 'pettycash_view_all_staff', 'pettycash_manage_all'];
        $oldIds = DB::table('permissions')->whereIn('key', $oldKeys)->pluck('id')->toArray();
        if (!empty($oldIds)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $oldIds)->delete();
            DB::table('permissions')->whereIn('id', $oldIds)->delete();
        }
    }

    public function down(): void
    {
        $keys = ['view_petty_cash', 'view_petty_cash_transactions', 'manage_petty_cash_settings'];
        $ids = DB::table('permissions')->whereIn('key', $keys)->pluck('id')->toArray();
        if (!empty($ids)) {
            DB::table('user_group_permissions')->whereIn('permission_id', $ids)->delete();
            DB::table('permissions')->whereIn('id', $ids)->delete();
        }

        DB::table('extensions')->where('id', 'pettycash')->delete();

        Schema::dropIfExists('petty_cash_user_roles');
        Schema::dropIfExists('petty_cash_transactions');
        Schema::dropIfExists('petty_cash_categories');
    }
};
