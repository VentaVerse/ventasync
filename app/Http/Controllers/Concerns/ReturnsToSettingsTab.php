<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\RedirectResponse;

/**
 * Every action on a channel's Settings page lands back on the tab its form
 * lives on (the owner, 2026-09-05: "it goes back to Settings > Connection").
 *
 * The page is one URL with ?tab= sections; the menubar's tab links are real
 * links, so a redirect that drops the query (or bounces through a storeless
 * landing) always resurfaces on Connection. Each action names its tab here,
 * the redirect carries it in the URL for the server-side section choice, and
 * flashes it so the page can draw a result inside that same section rather
 * than page-level. Store-scoped channels supply their own route and store,
 * so the landing is the store the operator was standing in.
 */
trait ReturnsToSettingsTab
{
    /** @return array{0: string, 1: array<string, mixed>} the settings page's route name and its store params */
    abstract protected function settingsTabRoute(): array;

    protected function toSettingsTab(string $tab): RedirectResponse
    {
        [$name, $params] = $this->settingsTabRoute();

        return redirect()->route($name, $params + ['tab' => $tab])->with('settings_tab', $tab);
    }
}
