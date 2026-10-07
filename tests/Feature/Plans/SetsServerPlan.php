<?php

namespace Tests\Feature\Plans;

use App\Extensions\ExtensionManager;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait SetsServerPlan
{
    protected function onServer(array $extensions = [], array $limits = []): ExtensionManager
    {
        $values = array_fill_keys(array_keys((array) config('plans.extensions')), null);
        foreach ($extensions as $id) {
            $values[$id] = '1';
        }

        config([
            'plans.extensions' => $values,
            'plans.limits' => array_merge(array_fill_keys(array_keys((array) config('plans.limits')), null), $limits),
        ]);
        $this->app->forgetInstance(ExtensionManager::class);

        return $this->app->make(ExtensionManager::class);
    }

    protected function selfHosted(): ExtensionManager
    {
        return $this->onServer();
    }

    protected function admin(): User
    {
        $this->app->make(ExtensionManager::class)->syncPermissions();

        return User::factory()->create([
            'user_group_id' => DB::table('user_groups')->where('name', 'Administrator')->value('id'),
        ]);
    }
}
