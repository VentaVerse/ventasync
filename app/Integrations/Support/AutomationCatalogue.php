<?php

namespace App\Integrations\Support;

use App\Extensions\ExtensionProvider;
use Illuminate\Contracts\Foundation\Application;

final class AutomationCatalogue
{
    public function __construct(private Application $app)
    {
    }

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

    public function fingerprint(): string
    {
        $declared = [];
        foreach ($this->providers() as $id => $provider) {
            $declared[$id] = $provider->automations();
        }

        return md5((string) json_encode($declared));
    }

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
