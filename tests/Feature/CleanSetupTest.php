<?php

namespace Tests\Feature;

use App\Support\Database\SchemaAligner;
use App\Support\Database\SchemaSnapshot;
use Tests\TestCase;

class CleanSetupTest extends TestCase
{
    public function test_the_baseline_list_names_the_setup_files_that_exist(): void
    {
        $baseline = require database_path('upgrades/baseline.php');
        $this->assertCount(20, $baseline);
        foreach ($baseline as $name) {
            $this->assertFileExists(database_path("migrations/{$name}.php"));
        }
    }

    public function test_the_old_migrations_are_gone_and_only_listed_for_the_upgrade(): void
    {
        $history = require database_path('upgrades/history.php');
        $this->assertCount(331, $history);
        foreach ($history as $name) {
            $this->assertFileDoesNotExist(database_path("migrations/{$name}.php"), "{$name} came back");
        }
    }

    public function test_the_upgrade_target_describes_every_table_the_setup_files_create(): void
    {
        $target = json_decode((string) file_get_contents(database_path('upgrades/schema.json')), true);
        $this->assertIsArray($target);
        $created = [];
        foreach (require database_path('upgrades/baseline.php') as $name) {
            preg_match_all("/Schema::create\('([a-z0-9_]+)'/", (string) file_get_contents(database_path("migrations/{$name}.php")), $m);
            array_push($created, ...$m[1]);
        }
        sort($created);
        $this->assertSame(array_keys($target), $created);
    }

    public function test_an_aligned_database_needs_no_statements(): void
    {
        $target = json_decode((string) file_get_contents(database_path('upgrades/schema.json')), true);
        $this->assertSame([], SchemaAligner::statements($target, $target));
        $this->assertSame([], SchemaSnapshot::differences($target, $target));
    }

    public function test_a_renamed_index_and_a_wider_column_are_planned_and_nothing_else(): void
    {
        $target = json_decode((string) file_get_contents(database_path('upgrades/schema.json')), true);
        $old = $target;
        $index = $old['warehouses']['indexes'];
        $name = array_key_first(array_diff_key($index, ['PRIMARY' => true]));
        $old['warehouses']['indexes']['old_name'] = $index[$name];
        unset($old['warehouses']['indexes'][$name]);
        foreach ($old['orders']['columns'] as &$column) {
            if ($column['name'] === 'shipping_address_1') {
                $column['type'] = 'varchar(128)';
            }
        }
        unset($column);

        $sql = SchemaAligner::statements($old, $target);

        $this->assertContains("ALTER TABLE `warehouses` RENAME INDEX `old_name` TO `vs_rename_0`", $sql);
        $this->assertContains("ALTER TABLE `warehouses` RENAME INDEX `vs_rename_0` TO `{$name}`", $sql);
        $this->assertContains("ALTER TABLE `orders` MODIFY `shipping_address_1` varchar(256) NOT NULL DEFAULT ''", $sql);
        $this->assertCount(5, $sql, 'two renames, one column, and the foreign key checks off and on');
    }

    public function test_an_unexpected_table_stops_the_upgrade_before_anything_changes(): void
    {
        $target = json_decode((string) file_get_contents(database_path('upgrades/schema.json')), true);
        $actual = $target + ['module_licenses' => $target['warehouses']];

        $this->expectExceptionMessage('table module_licenses is not expected');
        SchemaAligner::statements($actual, $target);
    }
}
