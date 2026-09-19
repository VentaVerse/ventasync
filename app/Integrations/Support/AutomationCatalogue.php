<?php

namespace App\Integrations\Support;

use App\Extensions\ExtensionProvider;
use Illuminate\Contracts\Foundation\Application;

/**
 * Every automation the switched-on extensions declare, kept in the
 * scheduled_jobs table.
 *
 * The table is what the scheduler runs and what the Automations tab shows, so
 * a declared job with no row is a job that silently never runs. Rows used to
 * be written once, by migrations, for whichever commands were registered at
 * that moment; an extension switched on afterwards, which is every extension
 * on a fresh install, got none. Here the declarations are the source, the way
 * the route table is for App\Services\PermissionCatalogue, and nobody
 * maintains the list by hand.
 *
 * Only switched-on extensions have a registered provider, so a switched-off
 * one declares nothing and core never names a channel.
 */
final class AutomationCatalogue
{
    public function __construct(private Application $app)
    {
    }

    /**
     * The providers that declare automations, by extension id.
     *
     * @return array<string, ExtensionProvider>
     */
    private function providers(): array
    {
        $found = [];
        foreach ($this->app->getProviders(ExtensionProvider::class) as $provider) {
            if ($provider->getId() !== '' && $provider->automations() !== []) {
                $found[$provider->getId()] = $provider;
            }
        }
        ksort($found);

        return $found;
    }

    /**
     * Changes whenever an extension is switched on or off, or a declaration
     * changes, and not otherwise. It reads no database, so checking it on
     * every boot costs nothing.
     */
    public function fingerprint(): string
    {
        $declared = [];
        foreach ($this->providers() as $id => $provider) {
            $declared[$id] = $provider->automations();
        }

        return md5((string) json_encode($declared));
    }

    /**
     * Add every declared automation that has no row yet. Nothing that exists
     * is changed, and every new row arrives switched off.
     *
     * @return int how many were created
     */
    public function sync(): int
    {
        $created = 0;
        foreach ($this->providers() as $id => $provider) {
            $jobs = $provider->automations();
            $stores = $provider->automationStoreIds();

            if ($stores === null) {
                $created += StoreScheduledJobs::ensure($id, null, $jobs);
                continue;
            }
            foreach ($stores as $storeId) {
                $created += StoreScheduledJobs::ensure($id, (int) $storeId, $jobs);
            }
        }

        return $created;
    }
}
