<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Alpine can only take back a class it put there itself.
 *
 * `:class="cond && 'x'"` ADDS 'x' when cond is true and does nothing when it is
 * false. It never REMOVES 'x'. That is harmless while Alpine is the only thing
 * writing the class, and it is a defect the moment the server renders the same
 * class into the static class attribute, because then nothing can take it off
 * again.
 *
 * The sidebar hit exactly that. nav-blotter renders is-open server-side on the
 * group containing the current route, deliberately, so the group you are in is
 * not animated open on every page load. Bound with &&, opening a SECOND group
 * left the first one visibly open at full height, while :inert - an attribute
 * binding, which Alpine does set and unset correctly - quietly disabled every
 * link inside it. A menu that stays on screen and silently ignores clicks.
 *
 * Measured on /channels/*: Channels sat at 258px with inert set.
 *
 * The object form states the class in both directions, so false removes it.
 */
class AlpineClassBindingTest extends TestCase
{
    private const NAV = 'resources/views/partials/nav-blotter.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    // ── The sidebar, specifically ────────────────────────────────────

    public function test_the_open_group_can_be_closed_again(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("\"{ 'is-open': group === '{{ \$key }}' }\"", $nav,
            'The open-state binding is no longer the object form, so Alpine cannot remove the '
            .'is-open the server rendered and a second group leaves the first stuck open.');

        // Matched as an ATTRIBUTE, not as a bare substring: the comment above
        // the binding quotes the broken form on purpose, to explain it, and a
        // plain search finds the documentation rather than the code.
        $this->assertDoesNotMatchRegularExpression('/:class="[^"]*&&\s*\'is-open\'/', $nav,
            'The && form is back on is-open. It can add the class but never take it away.');
    }

    public function test_the_chevron_can_turn_back(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("\"{ 'rotate-90': group === '{{ \$key }}' }\"", $nav);
        $this->assertDoesNotMatchRegularExpression('/:class="[^"]*&&\s*\'rotate-90\'/', $nav);
    }

    /**
     * Both halves have to stay. The server class is what stops the group you
     * are already in from animating open on load; the object binding is what
     * lets it close afterwards. Either one alone is a bug.
     */
    public function test_the_server_still_renders_the_open_group(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("{{ \$blOpenGroup === \$key ? 'is-open' : '' }}", $nav,
            'The server no longer marks the active group open, so it will animate open on every '
            .'page load after Alpine boots.');

        $this->assertStringContainsString("{{ \$blOpenGroup === \$key ? 'rotate-90' : '' }}", $nav,
            'The chevron of the group you are in will spin 90 degrees on every page load.');
    }

    /** One value cannot name two groups, which is what makes this an accordion. */
    public function test_one_group_open_at_a_time_is_held_in_one_place(): void
    {
        // Two values since the third layer (2026-09-06): one open group, one open channel under it.
        $this->assertStringContainsString('x-data="{ group: @js($blOpenGroup), sub: @js($blOpenSub) }"', $this->source(self::NAV),
            'The open group is no longer a single value on the nav, so two groups can be open at once.');
    }

    // ── The general rule, across every Blade file ────────────────────

    /**
     * No element may bind a class with && when the same class can also be
     * rendered into its static class attribute.
     *
     * Scanned rather than listed, so a new view cannot reintroduce this
     * quietly. The && form stays perfectly legal everywhere the server never
     * writes that class, which is every other use of it in this codebase.
     */
    public function test_no_element_binds_a_class_the_server_also_writes(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            // Each opening tag, so a static class and a binding are only
            // compared when they sit on the SAME element.
            preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('/:class="([^"]*)"/', $tag, $bind)) {
                    continue;
                }

                if (! str_contains($bind[1], '&&')) {
                    continue;
                }

                // The class names the binding can add.
                preg_match_all("/&&\s*'([^']+)'/", $bind[1], $bound);

                if (! preg_match('/(?<!:)\bclass="([^"]*)"/', $tag, $static)) {
                    continue;
                }

                foreach ($bound[1] ?? [] as $class) {
                    // Matched anywhere in the static attribute, INCLUDING inside
                    // a Blade expression: `{{ $open ? 'is-open' : '' }}` is
                    // exactly the case that broke the sidebar.
                    if (str_contains($static[1], $class)) {
                        $offenders[] = str_replace(base_path().'/', '', $file).": '{$class}'";
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "These elements bind a class with && that the server can also render, so Alpine can "
            ."add it but never remove it. Use the object form { 'x': cond } instead:\n  "
            .implode("\n  ", array_unique($offenders)));
    }

    /** @return array<int, string> */
    private function bladeFiles(): array
    {
        $files = [];

        foreach ([base_path('resources/views'), base_path('extensions')] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));

            foreach ($it as $f) {
                if ($f->isFile() && str_ends_with($f->getFilename(), '.blade.php')) {
                    $files[] = $f->getPathname();
                }
            }
        }

        return $files;
    }
}
