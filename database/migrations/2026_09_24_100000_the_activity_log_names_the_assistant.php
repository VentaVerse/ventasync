<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreignId('api_client_id')->nullable()->after('user_name')
                ->constrained('api_clients')->nullOnDelete();
        });

        $names = DB::table('api_clients')->select('name', DB::raw('MIN(id) as id'))
            ->groupBy('name')->havingRaw('COUNT(*) = 1')->pluck('id', 'name');

        foreach ($names as $name => $id) {
            DB::table('activity_logs')->where('source', 'api')->where('user_name', $name)
                ->update(['api_client_id' => $id]);
        }

        DB::table('activity_logs')->where('source', 'api')->whereNotNull('user_id')->update(['user_id' => null]);
        DB::table('stock_history')->where('source', 'api')->whereNotNull('user_id')->update(['user_id' => null]);
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_client_id');
        });
    }
};
