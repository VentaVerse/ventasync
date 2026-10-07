<?php

namespace Database\Seeders;

use App\Extensions\ExtensionManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    private const NOT_ON_A_NEW_INSTALL = ['pedallion'];

    public function run(): void
    {
        $this->seedCommunityExtensions();
    }

    private function seedCommunityExtensions(): void
    {
        $manager = app(ExtensionManager::class);

        $enabled  = [];
        $skipped  = [];
        $left     = [];

        foreach ($manager->getManifests() as $id => $manifest) {
            $tier = $manifest['tier'] ?? 'plus';

            if (in_array($id, self::NOT_ON_A_NEW_INSTALL, true)) {
                $left[] = $id;
                continue;
            }

            $known = DB::table('extensions')->where('id', $id)->exists();
            $manager->install($id);

            if ($tier === 'community' && ! $known) {
                $manager->enable($id);
                $enabled[] = $id;
            } elseif ($tier !== 'community') {
                $skipped[] = $id . ' (' . $tier . ')';
            }
        }

        $this->command->getOutput()->writeln('<bg=green;fg=white> ✓ Extensions registered </>');
        $this->command->newLine();
        if (!empty($enabled)) {
            $this->command->getOutput()->writeln('  Enabled (Community): <fg=cyan>' . implode(', ', $enabled) . '</>');
        }
        if (!empty($skipped)) {
            $this->command->getOutput()->writeln('  Installed but disabled (need license): <fg=yellow>' . implode(', ', $skipped) . '</>');
        }

        if (!empty($left)) {
            $this->command->getOutput()->writeln('  Not installed, add it from Settings > Extensions: <fg=yellow>' . implode(', ', $left) . '</>');
        }
        $this->command->newLine();
    }
}
