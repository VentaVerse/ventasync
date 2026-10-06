<?php

namespace Database\Seeders;

use App\Extensions\ExtensionManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps a fresh install: every Community-tier extension found on disk
 * is installed and enabled, and Plus-tier ones are installed but left off.
 *
 * No user is made. A new install opens on the first account page, and that
 * account is the administrator (App\Support\FirstAccount).
 *
 * Idempotent: running it again does not re-enable an extension switched off
 * by hand.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Extensions a new install does not start with, whatever tier they are.
     *
     * Everything else that is Community arrives enabled, because that is what
     * the product is. These are the ones only some sellers ever trade on.
     */
    private const NOT_ON_A_NEW_INSTALL = ['pedallion'];

    public function run(): void
    {
        $this->seedCommunityExtensions();
    }

    /**
     * Install + enable every Community-tier extension found on disk.
     *
     * Plus-tier extensions are intentionally skipped — they need license
     * activation through the future Plus marketplace UI, not auto-enable
     * on first install. If a Plus extension is on disk (e.g. a Plus
     * customer dropped one in before running migrate), it's registered
     * in the extensions table but left disabled.
     */
    private function seedCommunityExtensions(): void
    {
        /** @var ExtensionManager $manager */
        $manager = app(ExtensionManager::class);

        $enabled  = [];
        $skipped  = [];
        $left     = [];

        foreach ($manager->getManifests() as $id => $manifest) {
            $tier = $manifest['tier'] ?? 'plus';

            // Left out entirely, not installed and disabled: a channel only
            // some sellers trade on should not be part of what a new install
            // starts with. Settings > Extensions lists every extension on
            // disk, so it is one click away for the installs that want it.
            if (in_array($id, self::NOT_ON_A_NEW_INSTALL, true)) {
                $left[] = $id;
                continue;
            }

            // Install the row regardless of tier so the /extensions UI
            // sees Plus extensions as "installed but disabled" rather
            // than missing entirely.
            $known = DB::table('extensions')->where('id', $id)->exists();
            $manager->install($id);

            // Only on the way in. Switching a channel back on because the
            // seeder ran again would undo a decision the operator made.
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
