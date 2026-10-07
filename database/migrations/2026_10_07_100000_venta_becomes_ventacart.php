<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const VALUE_COLUMNS = [
        'order' => ['marketplace_source'],
        'listing_readiness' => ['channel'],
        'listing_store_skus' => ['channel'],
        'listing_variation_offs' => ['channel'],
        'channel_webhook_events' => ['channel'],
        'description_templates' => ['integration'],
        'watermark_templates' => ['integration'],
        'automation_runs' => ['integration'],
        'order_fetch_runs' => ['integration'],
        'scheduled_jobs' => ['integration', 'command'],
        'marketplace_reviews' => ['platform'],
        'activity_logs' => ['source'],
        'stock_history' => ['source'],
        'module_licenses' => ['module_slug'],
    ];

    public function up(): void
    {
        $this->move('venta', 'ventacart');
    }

    public function down(): void
    {
        $this->move('ventacart', 'venta');
    }

    private function move(string $from, string $to): void
    {
        $prefix = DB::getTablePrefix();
        $token = fn (string $value) => preg_replace('/(?<![a-z])' . $from . '(?![a-z])/', $to, $value);
        $database = DB::connection()->getDatabaseName();

        foreach (DB::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_name LIKE ?', [$database, $prefix . $from . '\_%']) as $row) {
            $old = substr($row->t, strlen($prefix));
            $new = $to . substr($old, strlen($from));
            if (! Schema::hasTable($new)) {
                Schema::rename($old, $new);
            }
        }

        $columns = DB::select(
            'SELECT table_name AS t, column_name AS c FROM information_schema.columns WHERE table_schema = ? AND table_name LIKE ? AND column_name REGEXP ?',
            [$database, $prefix . '%', '(^|_)' . $from . '(_|$)']
        );
        foreach ($columns as $row) {
            $table = substr($row->t, strlen($prefix));
            $new = $token($row->c);
            if ($new !== $row->c && ! Schema::hasColumn($table, $new)) {
                $this->renameColumn($database, $row->t, $row->c, $new);
            }
        }

        foreach (self::VALUE_COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
                    continue;
                }
                $values = DB::table($table)->whereRaw('`' . $col . '` REGEXP ?', ['(^|[^a-z])' . $from . '([^a-z]|$)'])->distinct()->pluck($col);
                foreach ($values as $value) {
                    DB::table($table)->where($col, $value)->update([$col => $token((string) $value)]);
                }
            }
        }

        if (Schema::hasTable('activity_logs')) {
            [$oldLabel, $newLabel] = $to === 'ventacart' ? ['Venta Store', 'VentaCart'] : ['VentaCart', 'Venta Store'];
            DB::table('activity_logs')->where('subject_type', $oldLabel)->update(['subject_type' => $newLabel]);
        }

        $this->movePermissions($from, $to);
        $this->moveExtensionRow($from, $to);

        $awb = storage_path('app/' . $from . '-awb');
        if (File::isDirectory($awb) && ! File::exists(storage_path('app/' . $to . '-awb'))) {
            File::moveDirectory($awb, storage_path('app/' . $to . '-awb'));
        }
        File::deleteDirectory(public_path('images/extensions/' . $from));
    }

    private function renameColumn(string $database, string $table, string $old, string $new): void
    {
        $rename = 'ALTER TABLE `' . $table . '` RENAME COLUMN `' . $old . '` TO `' . $new . '`';
        try {
            DB::statement($rename . ', ALGORITHM=INPLACE');

            return;
        } catch (\Illuminate\Database\QueryException) {
        }

        // A table MySQL must copy cannot rename a foreign key column, so the key is dropped and put back.
        $keys = DB::select(
            'SELECT k.constraint_name AS name, k.referenced_table_name AS ref_table, k.referenced_column_name AS ref_column, r.delete_rule AS on_delete, r.update_rule AS on_update
             FROM information_schema.key_column_usage k
             JOIN information_schema.referential_constraints r ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
             WHERE k.table_schema = ? AND k.table_name = ? AND k.column_name = ? AND k.referenced_table_name IS NOT NULL',
            [$database, $table, $old]
        );
        foreach ($keys as $key) {
            DB::statement('ALTER TABLE `' . $table . '` DROP FOREIGN KEY `' . $key->name . '`');
        }
        DB::statement($rename);
        foreach ($keys as $key) {
            DB::statement(sprintf(
                'ALTER TABLE `%s` ADD CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES `%s` (`%s`) ON DELETE %s ON UPDATE %s',
                $table, $table . '_' . $new . '_foreign', $new, $key->ref_table, $key->ref_column, $key->on_delete, $key->on_update
            ));
        }
    }

    private function movePermissions(string $from, string $to): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        [$leafFrom, $leafTo] = $to === 'ventacart' ? ['venta', 'venta_cart'] : ['venta_cart', 'venta'];

        $rows = DB::table('permissions')->where('key', 'like', '%\_' . $from . '/%')->get();
        foreach ($rows as $row) {
            if (! preg_match('#^([a-z]+)_' . $from . '/(.+)$#', $row->key, $m)) {
                continue;
            }
            $leaf = preg_replace('/(?<![a-z])' . $leafFrom . '(?![a-z])/', $leafTo, $m[2]);
            $newKey = $m[1] . '_' . $to . '/' . $leaf;

            $existing = DB::table('permissions')->where('key', $newKey)->value('id');
            if ($existing === null) {
                DB::table('permissions')->where('id', $row->id)->update(['key' => $newKey]);
                continue;
            }

            if (Schema::hasTable('user_group_permissions')) {
                $groups = DB::table('user_group_permissions')->where('permission_id', $row->id)->pluck('user_group_id');
                foreach ($groups as $groupId) {
                    DB::table('user_group_permissions')->insertOrIgnore(['user_group_id' => $groupId, 'permission_id' => $existing]);
                }
                DB::table('user_group_permissions')->where('permission_id', $row->id)->delete();
            }
            DB::table('permissions')->where('id', $row->id)->delete();
        }
    }

    private function moveExtensionRow(string $from, string $to): void
    {
        if (! Schema::hasTable('extensions')) {
            return;
        }

        $row = DB::table('extensions')->where('id', $from)->first();
        if ($row === null || DB::table('extensions')->where('id', $to)->exists()) {
            return;
        }

        $manifest = json_decode((string) $row->manifest, true) ?: [];
        $file = base_path('extensions/' . $to . '/extension.json');
        if (File::exists($file)) {
            $manifest = json_decode(File::get($file), true) ?: $manifest;
        }
        $manifest['id'] = $to;

        DB::table('extensions')->where('id', $from)->update([
            'id' => $to,
            'name' => $to === 'ventacart' ? ($manifest['name'] ?? 'VentaCart') : 'Venta Store',
            'description' => $manifest['description'] ?? $row->description,
            'manifest' => json_encode($manifest),
        ]);
    }
};
