<?php

namespace Tests\Feature;

use Tests\TestCase;

class MigrationOwnershipTest extends TestCase
{
    private const NAMES = "/(?:Schema::(?:create|table|drop|dropIfExists|rename|hasTable|hasColumn)|DB::table|->on)\(\s*'([a-z0-9_]+)'/";

    private function owners(): array
    {
        $owners = [];
        foreach (glob(base_path('extensions/*/migrations/*.php')) ?: [] as $file) {
            preg_match_all("/Schema::create\(\s*'([a-z0-9_]+)'/", (string) file_get_contents($file), $found);
            foreach ($found[1] as $table) {
                $owners[$table] = basename(dirname($file, 2));
            }
        }

        return $owners;
    }

    private function named(string $file): array
    {
        preg_match_all(self::NAMES, (string) file_get_contents($file), $found);

        return array_unique($found[1]);
    }

    public function test_extensions_carry_migrations(): void
    {
        $this->assertNotEmpty($this->owners(), 'no extension migrations were found');
        $this->assertArrayHasKey('ch_shopee_orders', $this->owners());
    }

    public function test_core_migrations_never_name_an_extensions_table(): void
    {
        $owners = $this->owners();
        $offenders = [];
        foreach (glob(database_path('migrations/*.php')) as $file) {
            foreach ($this->named($file) as $table) {
                if (isset($owners[$table])) {
                    $offenders[] = basename($file) . " names {$table} ({$owners[$table]})";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_an_extensions_migrations_never_name_another_extensions_table(): void
    {
        $owners = $this->owners();
        $offenders = [];
        foreach (glob(base_path('extensions/*/migrations/*.php')) ?: [] as $file) {
            $id = basename(dirname($file, 2));
            foreach ($this->named($file) as $table) {
                if (isset($owners[$table]) && $owners[$table] !== $id) {
                    $offenders[] = "{$id}/" . basename($file) . " names {$table} ({$owners[$table]})";
                }
            }
        }

        $this->assertSame([], $offenders);
    }

    public function test_every_extension_migration_can_be_rolled_back(): void
    {
        foreach (glob(base_path('extensions/*/migrations/*.php')) ?: [] as $file) {
            $this->assertStringContainsString('public function down(): void', (string) file_get_contents($file), basename($file));
        }
    }

    public function test_a_migration_name_is_never_used_twice(): void
    {
        $names = array_map('basename', array_merge(glob(database_path('migrations/*.php')), glob(base_path('extensions/*/migrations/*.php')) ?: []));

        $this->assertSame(array_unique($names), $names);
    }
}
