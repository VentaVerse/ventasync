<?php

namespace App\Console\Commands;

use App\Support\Database\SchemaSnapshot;
use Illuminate\Console\Command;

class DatabaseSchemaSnapshot extends Command
{
    protected $signature = 'db:schema-snapshot
                            {--write= : Save the schema of this database to a JSON file}
                            {--compare= : Compare this database with a saved JSON file}';

    protected $description = 'Describe the database schema the same way on MySQL and MariaDB, save it, or compare it with a saved one';

    public function handle(): int
    {
        $schema = SchemaSnapshot::read();

        if ($path = $this->option('write')) {
            file_put_contents($path, json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
            $this->info(count($schema) . ' tables written to ' . $path);
        }

        if ($path = $this->option('compare')) {
            $target = json_decode((string) file_get_contents($path), true);
            $differences = SchemaSnapshot::differences($schema, $target);
            foreach ($differences as $line) {
                $this->line($line);
            }
            $this->info(count($differences) . ' differences');

            return $differences === [] ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
