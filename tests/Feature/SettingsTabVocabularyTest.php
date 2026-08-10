<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * One name for one thing, across every marketplace.
 *
 * The same panel had different ids and different labels depending on which
 * channel you opened. Connection was `settings` on four channels and
 * `connection` on three. The API log was `logs` on four and `api` on two. The
 * run history was "Sync log" on two and "Run log" on OpenCart. The scheduled
 * jobs panel was "Automations" on four and "Scheduling" on OpenCart and Venta.
 *
 * That matters more than tidiness now that each tab owns a URL: ?tab=logs
 * opening the API log on Shopee and nothing at all on Venta is a broken link,
 * not an inconsistency. It also makes an operator who works across channels in
 * one session re-learn the same page six times.
 *
 * A channel may still have panels no other channel has - OpenCart's Order sync
 * and First import are real and unique. What it may not do is invent a second
 * name for something that already has one.
 */
class SettingsTabVocabularyTest extends TestCase
{
    /**
     * The shared vocabulary: tab id => the label every channel must use.
     * A channel that has this panel must name it this.
     */
    private const VOCABULARY = [
        'connection' => 'Connection',
        'status' => 'Status mapping',
        'explorer' => 'API explorer',
        'logs' => 'API log',
        'sync' => 'Sync log',
        'automations' => 'Automations',
    ];

    /** Panels that genuinely belong to one channel and need no counterpart. */
    private const CHANNEL_SPECIFIC = ['orders', 'import'];

    /** @return array<string, string> channel => view source */
    private function settingsViews(): array
    {
        $out = [];

        // Two shapes only: extensions/{c}/views/index.blade.php and
        // extensions/{c}/views/settings/index.blade.php. Globbed explicitly
        // rather than with ** , which PHP's glob() does not recurse.
        $paths = array_merge(
            glob(base_path('extensions/*/views/index.blade.php')) ?: [],
            glob(base_path('extensions/*/views/settings/index.blade.php')) ?: []
        );

        foreach ($paths as $path) {
            $source = file_get_contents($path);

            if (! str_contains($source, 'data-cs-tab=')) {
                continue;
            }

            // extensions/{channel}/views/...
            $relative = str_replace(base_path().'/extensions/', '', $path);
            $out[strtok($relative, '/')] = $source;
        }

        return $out;
    }

    public function test_the_channels_are_found(): void
    {
        $this->assertGreaterThanOrEqual(6, count($this->settingsViews()),
            'Fewer settings pages with tabs than expected; the discovery is probably broken.');
    }

    /** No channel may invent a second id for a panel that already has one. */
    public function test_every_tab_id_is_from_the_shared_vocabulary(): void
    {
        $known = array_merge(array_keys(self::VOCABULARY), self::CHANNEL_SPECIFIC);

        foreach ($this->settingsViews() as $channel => $source) {
            preg_match_all('/data-cs-tab="[a-z]{2}-tab-([a-z-]+)"/', $source, $m);

            foreach (array_unique($m[1]) as $id) {
                $this->assertContains($id, $known,
                    "{$channel} names a tab '{$id}', which is not in the shared vocabulary. If it is the "
                    .'same panel another channel has, use that channel\'s id; if it is genuinely new, add '
                    .'it here so the next channel copies the name rather than inventing a second one.');
            }
        }
    }

    /** And the label must match too: one id showing two names is the same defect. */
    public function test_every_shared_tab_carries_the_shared_label(): void
    {
        foreach ($this->settingsViews() as $channel => $source) {
            foreach (self::VOCABULARY as $id => $label) {
                if (! preg_match('/data-cs-tab="[a-z]{2}-tab-'.$id.'"[^>]*>(.*?)<\/button>/s', $source, $m)) {
                    continue;
                }

                $rendered = trim(strip_tags($m[1]));
                // A tab may carry a count badge after its name.
                $this->assertStringStartsWith($label, $rendered,
                    "{$channel} labels its '{$id}' tab \"{$rendered}\" where every other channel says \"{$label}\".");
            }
        }
    }

    /**
     * The panel a tab points at must exist. A rename that misses one reference
     * leaves a button controlling nothing, and the tab module silently shows
     * no panel at all.
     */
    public function test_every_tab_button_points_at_a_panel_that_exists(): void
    {
        foreach ($this->settingsViews() as $channel => $source) {
            preg_match_all('/data-cs-tab="([a-z]{2}-tab-[a-z-]+)"/', $source, $m);

            foreach (array_unique($m[1]) as $panelId) {
                $this->assertStringContainsString('id="'.$panelId.'"', $source,
                    "{$channel} has a tab button for '{$panelId}' but no panel with that id.");
            }
        }
    }
}
