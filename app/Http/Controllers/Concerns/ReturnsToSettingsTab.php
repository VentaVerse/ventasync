<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;

trait ReturnsToSettingsTab
{
    abstract protected function settingsTabRoute(): array;

    protected function toSettingsTab(string $tab): RedirectResponse
    {
        [$name, $params] = $this->settingsTabRoute();

        return redirect()->route($name, $params + ['tab' => $tab])->with('settings_tab', $tab);
    }
}
