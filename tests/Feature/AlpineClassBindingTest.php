<?php

namespace Tests\Feature;

use Tests\TestCase;

class AlpineClassBindingTest extends TestCase
{
    private const NAV = 'resources/views/partials/nav-blotter.blade.php';

    private function source(string $file): string
    {
        return file_get_contents(base_path($file));
    }

    public function test_the_open_group_can_be_closed_again(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("\"{ 'is-open': group === '{{ \$key }}' }\"", $nav,
            'The open-state binding is no longer the object form, so Alpine cannot remove the '
            .'is-open the server rendered and a second group leaves the first stuck open.');

        $this->assertDoesNotMatchRegularExpression('/:class="[^"]*&&\s*\'is-open\'/', $nav,
            'The && form is back on is-open. It can add the class but never take it away.');
    }

    public function test_the_chevron_can_turn_back(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("\"{ 'rotate-90': group === '{{ \$key }}' }\"", $nav);
        $this->assertDoesNotMatchRegularExpression('/:class="[^"]*&&\s*\'rotate-90\'/', $nav);
    }

    public function test_the_server_still_renders_the_open_group(): void
    {
        $nav = $this->source(self::NAV);

        $this->assertStringContainsString("{{ \$blOpenGroup === \$key ? 'is-open' : '' }}", $nav,
            'The server no longer marks the active group open, so it will animate open on every '
            .'page load after Alpine boots.');

        $this->assertStringContainsString("{{ \$blOpenGroup === \$key ? 'rotate-90' : '' }}", $nav,
            'The chevron of the group you are in will spin 90 degrees on every page load.');
    }

    public function test_one_group_open_at_a_time_is_held_in_one_place(): void
    {
        $this->assertStringContainsString('x-data="{ group: @js($blOpenGroup), sub: @js($blOpenSub) }"', $this->source(self::NAV),
            'The open group is no longer a single value on the nav, so two groups can be open at once.');
    }

    public function test_no_element_binds_a_class_the_server_also_writes(): void
    {
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            $source = file_get_contents($file);

            preg_match_all('/<[a-zA-Z][^>]*>/s', $source, $tags);

            foreach ($tags[0] as $tag) {
                if (! preg_match('/:class="([^"]*)"/', $tag, $bind)) {
                    continue;
                }

                if (! str_contains($bind[1], '&&')) {
                    continue;
                }

                preg_match_all("/&&\s*'([^']+)'/", $bind[1], $bound);

                if (! preg_match('/(?<!:)\bclass="([^"]*)"/', $tag, $static)) {
                    continue;
                }

                foreach ($bound[1] ?? [] as $class) {
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
