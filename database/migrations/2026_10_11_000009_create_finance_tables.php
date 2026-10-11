<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('petty_cash_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 128);
            $table->integer('sort_order')->default(0);
            $table->boolean('status')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        Schema::create('petty_cash_transactions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('type', ['credit', 'expense']);
            $table->decimal('amount', 12, 2);
            $table->string('category', 128)->nullable();
            $table->text('description')->nullable();
            $table->date('transaction_date');
            $table->unsignedBigInteger('created_by');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['user_id', 'transaction_date'], 'petty_cash_transactions_user_id_transaction_date_index');
        });

        Schema::create('petty_cash_user_roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('role', ['admin', 'staff'])->default('staff');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['user_id'], 'petty_cash_user_roles_user_id_unique');
        });

        Schema::table('petty_cash_transactions', function (Blueprint $table) {
            $table->foreign('created_by', 'petty_cash_transactions_created_by_foreign')->references('id')->on('users');
            $table->foreign('user_id', 'petty_cash_transactions_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('petty_cash_user_roles', function (Blueprint $table) {
            $table->foreign('user_id', 'petty_cash_user_roles_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('petty_cash_user_roles');
        Schema::dropIfExists('petty_cash_transactions');
        Schema::dropIfExists('petty_cash_categories');
        Schema::enableForeignKeyConstraints();
    }
};
