<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    private const PRIMITIVE_OWNERS = [
        'bl',
        'od',
        'fm',
        'fh',
        'cw',
        'cs',
        'api',
        'pc',
        'inv',
        'int',
    ];

    private const RETIRED_TOKEN_PATTERN =
        '/(?<![\w-])--(?:canvas|surface(?:-[a-z]+)?|border(?:-[a-z-]+)?|text(?:-[a-z]+)?|ink|muted'
        .'|accent(?:-[a-z]+)?|danger(?:-hover)?|warning|info|success-(?:ink|soft)|chart-(?:money|count)'
        .'|radius-(?:sm|md|lg)|shadow-(?:sm|menu|modal)|transition-fast|font-ui|control-h(?:-sm)?|row-h'
        .'|sidebar-w-new|motion-(?:fast|base)|space-[1-8])(?![\w-])/';

    public function test_no_new_vocabulary_defines_its_own_layout_primitive(): void
    {
        $found = [];

        foreach ($this->stylesheets() as $path) {
            preg_match_all(
                '/\.([a-z]+)-(?:section|sec|card|head|sheet|inset|panel)\s*\{/',
                File::get($path),
                $matches
            );

            foreach ($matches[1] as $prefix) {
                $found[$prefix] ??= [];
                $found[$prefix][] = str_replace(base_path().'/', '', $path);
            }
        }

        $new = array_diff(array_keys($found), self::PRIMITIVE_OWNERS);

        $detail = '';
        foreach ($new as $prefix) {
            $detail .= "\n  {$prefix}- in " . implode(', ', array_unique($found[$prefix]));
        }

        $this->assertSame(
            [],
            array_values($new),
            "A new vocabulary has defined its own section, card or head.\n"
            ."Use .bl-sheet, .bl-inset or .bl-head, or add a variant of one of them.\n"
            ."If this really is a new primitive the foundation needs, add the prefix to\n"
            ."FoundationTest::PRIMITIVE_OWNERS and say why in the commit.{$detail}"
        );
    }

    public function test_the_primitive_allowlist_names_nothing_that_is_already_clean(): void
    {
        $found = [];

        foreach ($this->stylesheets() as $path) {
            preg_match_all(
                '/\.([a-z]+)-(?:section|sec|card|head|sheet|inset|panel)\s*\{/',
                File::get($path),
                $matches
            );
            $found = array_merge($found, $matches[1]);
        }

        $found = array_unique($found);

        $stale = array_diff(self::PRIMITIVE_OWNERS, $found, ['bl']);

        $this->assertSame(
            [],
            array_values($stale),
            "These prefixes no longer define any layout primitive and should be\n"
            ."removed from FoundationTest::PRIMITIVE_OWNERS: "
            .implode(', ', $stale)
        );
    }

    public function test_the_retired_v22_token_vocabulary_stays_retired(): void
    {
        $revived = [];

        foreach ($this->stylesheets() as $path) {
            $key = str_replace(resource_path('css').'/', '', $path);

            if (preg_match_all(self::RETIRED_TOKEN_PATTERN, File::get($path), $m)) {
                $revived[] = "{$key}: ".implode(', ', array_unique($m[0]));
            }
        }

        $this->assertSame(
            [],
            $revived,
            "A stylesheet speaks the retired v2.2 token vocabulary.\n"
            ."Use the --bl-* tokens listed at the top of blotter.css:\n\n"
            .implode("\n", $revived)
        );
    }

    public function test_a_shell_page_titles_itself_with_the_shared_class(): void
    {
        $offenders = [];

        foreach ($this->shellViews() as $path => $source) {
            preg_match_all('/<h1\b[^>]*>/', $source, $matches);

            foreach ($matches[0] as $tag) {
                if (! preg_match('/class="[^"]*\b(x-page-title|fm-title)\b/', $tag)) {
                    $offenders[] = $path . ': ' . trim($tag);
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A page on the application shell titles itself with its own markup.\n"
            ."Use <h1 class=\"x-page-title\"> inside .x-list-head, so the page sits on\n"
            ."the same head every other page uses.\n\n"
            .implode("\n", $offenders)
        );
    }

    public function test_no_query_prefixes_a_table_this_application_owns(): void
    {
        $ownTables = [
            'product_option_combinations',
            'product_option_combination_values',
        ];

        $offenders = [];
        foreach ([app_path(), base_path('extensions')] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }
            foreach (File::allFiles($root) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $src = (string) file_get_contents($file->getPathname());
                foreach ($ownTables as $table) {
                    if (preg_match('/(\$\w+|\))\s*\.\s*[\'"]' . preg_quote($table, '/') . '/', $src)) {
                        $offenders[] = str_replace(base_path() . '/', '', $file->getPathname()) . ' -> ' . $table;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            "These queries put the catalog prefix on a table this application owns. It works only where the "
            . "prefix is empty, and silently queries a table that does not exist on live:\n" . implode("\n", $offenders));
    }

    public function test_every_import_gives_the_product_a_code(): void
    {
        $importers = glob(base_path('extensions/*/Services/*/*ItemImport.php'));

        $this->assertGreaterThan(4, count($importers), 'The importers moved; this guard is looking in the wrong place.');

        $missing = [];
        foreach ($importers as $file) {
            $src = (string) file_get_contents($file);
            if (! str_contains($src, 'SkuFallback::fill')) {
                $missing[] = str_replace(base_path() . '/', '', $file);
            }
        }

        $this->assertSame([], $missing,
            "These importers can create a catalog product with no SKU. A product with no code cannot be "
            . "matched back to its listing, so the next push creates a duplicate:\n" . implode("\n", $missing));
    }

    private function shellViews(): array
    {
        $views = [];

        foreach ([resource_path('views'), base_path('extensions')] as $root) {
            if (! File::isDirectory($root)) {
                continue;
            }

            foreach (File::allFiles($root) as $file) {
                if (! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $source = File::get($file->getPathname());

                if (! preg_match("/@extends\('layouts\.(blotter|channel)'\)/", $source)) {
                    continue;
                }

                $views[str_replace(base_path().'/', '', $file->getPathname())] = $source;
            }
        }

        return $views;
    }

    public function test_no_stylesheet_uses_an_undeclared_custom_property(): void
    {
        $declared = [];
        $used = [];

        foreach ($this->stylesheets() as $path) {
            $source = File::get($path);
            $key = str_replace(resource_path('css').'/', '', $path);

            preg_match_all('/(--[a-z0-9-]+)\s*:/i', $source, $d);
            $declared = array_merge($declared, $d[1]);

            preg_match_all('/var\(\s*(--[a-z0-9-]+)/i', $source, $u);
            foreach ($u[1] as $prop) {
                $used[$prop] ??= $key;
            }
        }

        $declared = array_flip($declared);

        $missing = [];
        foreach ($used as $prop => $firstSeenIn) {
            if (str_starts_with($prop, '--tw-')) {
                continue;
            }
            if (! isset($declared[$prop])) {
                $missing[] = "{$prop} (first used in {$firstSeenIn})";
            }
        }

        sort($missing);

        $this->assertSame(
            [],
            $missing,
            "A stylesheet references a custom property nothing declares.\n"
            ."These resolve to nothing and render unstyled rather than erroring:\n\n"
            .implode("\n", $missing)
        );
    }

    public function test_every_label_the_phone_card_reorders_is_emitted_by_a_panel(): void
    {
        $path = resource_path('css/pages/marketplace-common.css');
        $this->assertFileExists($path, 'the stylesheet carrying the phone card order is gone');

        preg_match_all(
            "/td\[data-label='([^']+)'\]/",
            File::get($path),
            $m
        );

        $targeted = array_unique($m[1]);
        $this->assertNotEmpty($targeted, 'the phone card no longer orders anything by label');

        $panels = array_merge(
            glob(base_path('extensions/*/views/orders/_panel.blade.php')) ?: [],
            glob(base_path('extensions/*/views/orders/_returns_panel.blade.php')) ?: [],
        );
        $this->assertNotEmpty($panels, 'no fulfilment panels found to check');

        $emitted = [];
        foreach ($panels as $panel) {
            preg_match_all('/data-label="([^"]+)"/', File::get($panel), $p);
            foreach ($p[1] as $label) {
                $emitted[$label] = true;
            }
        }

        foreach (['Fulfilment' => 'shopify'] as $label => $channel) {
            if (! is_dir(base_path("extensions/{$channel}"))) {
                $emitted[$label] = true;
            }
        }

        $stale = array_values(array_diff($targeted, array_keys($emitted)));

        $this->assertSame([], $stale, sprintf(
            "The phone card orders cells no panel emits, so they sit at the default position:\n  %s\n"
            ."Either a column was renamed and marketplace-common.css was not, or the rule is dead.\n"
            .'Labels the panels do emit: %s',
            implode("\n  ", $stale),
            implode(', ', array_keys($emitted))
        ));
    }

    public function test_no_returns_panel_hides_a_row_action_behind_a_menu(): void
    {
        $panels = glob(base_path('extensions/*/views/orders/_returns_panel.blade.php')) ?: [];

        $this->assertNotEmpty($panels, 'no returns panels found to check');

        $hiding = [];

        foreach ($panels as $panel) {
            $channel = basename(dirname($panel, 3));
            $markup = File::get($panel);

            if (str_contains($markup, '<x-ui.menu')) {
                $hiding[] = $channel;
            }
        }

        $this->assertSame([], $hiding, sprintf(
            "These returns panels put row actions behind an overflow menu: %s\n\n"
            ."Returns actions are not destructive, and the sibling panel shows them as buttons.\n"
            ."If you are adding a menu for a DESTRUCTIVE action, that is the sanctioned case -\n"
            ."say so here and allow that channel.",
            implode(', ', $hiding)
        ));
    }

    public function test_both_returns_panels_open_a_return_from_the_row(): void
    {
        foreach (['lazada' => 'Open order', 'shopee' => 'Open return'] as $channel => $label) {
            $path = base_path("extensions/{$channel}/views/orders/_returns_panel.blade.php");
            $this->assertFileExists($path);

            $markup = File::get($path);

            $this->assertStringContainsString(
                $label,
                $markup,
                "{$channel} returns no longer offers \"{$label}\""
            );
            $this->assertStringContainsString(
                '<x-ui.button size="sm"',
                $markup,
                "{$channel} returns does not use the shared small button for its row action"
            );
        }
    }

    public function test_every_channel_class_a_fulfilment_panel_emits_has_a_rule(): void
    {
        $css = '';
        foreach ($this->stylesheets() as $path) {
            $css .= File::get($path);
        }

        $panels = array_merge(
            glob(base_path('extensions/*/views/orders/_panel.blade.php')) ?: [],
            glob(base_path('extensions/*/views/orders/_returns_panel.blade.php')) ?: [],
        );

        $this->assertNotEmpty($panels, 'no fulfilment panels found to check');

        $shared = ['x', 'co', 'is', 'js', 'has', 'no', 'sr'];

        $orphans = [];

        foreach ($panels as $panel) {
            $channel = basename(dirname($panel, 3));
            preg_match_all('/class="([^"{}]*)"/', File::get($panel), $m);

            foreach ($m[1] as $attr) {
                foreach (preg_split('/\s+/', trim($attr)) as $class) {
                    if ($class === '' || ! preg_match('/^([a-z]{2,3})-/', $class, $p)) {
                        continue;
                    }
                    if (in_array($p[1], $shared, true)) {
                        continue;
                    }
                    if (str_contains($css, '.'.$class)) {
                        continue;
                    }
                    $orphans[] = "{$class} (emitted by {$channel})";
                }
            }
        }

        $orphans = array_values(array_unique($orphans));
        sort($orphans);

        $this->assertSame(
            [],
            $orphans,
            "A fulfilment panel emits a channel class that no stylesheet defines.\n"
            ."These render unstyled - a column with no width, a control with no box:\n\n"
            .implode("\n", $orphans)
        );
    }

    public function test_every_page_stylesheet_is_imported_by_the_bundle(): void
    {
        $bundle = File::get(resource_path('css/blotter.css'));

        $unimported = [];
        foreach (glob(resource_path('css/pages/*.css')) ?: [] as $path) {
            $name = basename($path);
            if (! str_contains($bundle, 'pages/'.$name)) {
                $unimported[] = 'pages/'.$name;
            }
        }

        sort($unimported);

        $this->assertSame(
            [],
            $unimported,
            "A page stylesheet exists but nothing imports it, so none of it reaches a page:\n\n"
            .implode("\n", $unimported)
        );
    }

    public function test_the_accent_floor_is_measured_against_the_ink_it_carries(): void
    {
        $css = File::get(resource_path('css/blotter.css'));

        preg_match('/--bl-paper:\s*(#[0-9a-f]{6})/i', $css, $m);
        $this->assertNotEmpty($m, '--bl-paper is not declared as a plain hex in blotter.css');

        $paper = array_map('hexdec', str_split(ltrim($m[1], '#'), 2));

        $reflected = new \ReflectionClass(\App\Support\ChannelAccent::class);
        $ink = $reflected->getConstant('LIGHT_INK_ON_ACCENT');

        $this->assertSame(
            $paper,
            $ink,
            'ChannelAccent::LIGHT_INK_ON_ACCENT and --bl-paper have drifted apart. '
            ."deep() would then guarantee contrast against a colour the UI never paints.\n"
            .'--bl-paper is '.$m[1].'; the constant is rgb('.implode(', ', $ink).').'
        );
    }

    public function test_the_alert_clearance_is_measured_against_the_alert_colour(): void
    {
        $css = File::get(resource_path('css/blotter.css'));

        preg_match('/--bl-bad:\s*(#[0-9a-f]{6})/i', $css, $m);
        $this->assertNotEmpty($m, '--bl-bad is not declared as a plain hex in blotter.css');

        $bad = array_map('hexdec', str_split(ltrim($m[1], '#'), 2));

        $reflected = new \ReflectionClass(\App\Support\ChannelAccent::class);
        $alert = $reflected->getConstant('ALERT');

        $this->assertSame(
            $bad,
            $alert,
            'ChannelAccent::ALERT and --bl-bad have drifted apart, so the guard '
            ."that keeps a primary button from looking destructive is measuring\n"
            .'against a colour the UI does not paint. --bl-bad is '.$m[1]
            .'; the constant is rgb('.implode(', ', $alert).').'
        );
    }

    public function test_only_a_channel_that_reads_as_the_alert_colour_gives_up_its_own(): void
    {
        $this->assertSame(
            '#0b1228',
            \App\Support\ChannelAccent::cta('#EE4D2D'),
            'Shopee\'s orange reads as the alert red on a filled button and must fall back to the neutral'
        );

        foreach (['#0F146D' => 'Lazada', '#000000' => 'TikTok', '#1a8f5f' => 'VentaCart'] as $hex => $name) {
            $this->assertSame(
                \App\Support\ChannelAccent::deep($hex),
                \App\Support\ChannelAccent::cta($hex),
                $name.' is nowhere near the alert colour and must keep its own on the primary button'
            );
        }
    }

    public function test_every_channel_accent_holds_its_text_at_the_floor(): void
    {
        $accents = [];
        foreach (glob(base_path('extensions/*/extension.json')) ?: [] as $manifest) {
            $json = json_decode(File::get($manifest), true);
            $accent = $json['accent'] ?? ($json['nav']['accent'] ?? null);
            if (is_string($accent) && preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
                $accents[basename(dirname($manifest))] = $accent;
            }
        }

        $accents += [
            'shopee' => '#ee4d2d',
            'lazada' => '#0f146d',
            'tiktok' => '#1e1e1e',
            'ventacart'  => '#7c3aed',
        ];

        $failures = [];
        foreach ($accents as $channel => $hex) {
            $deep = \App\Support\ChannelAccent::deep($hex);
            $ratio = $this->contrastRatio(
                array_map('hexdec', str_split(ltrim($deep, '#'), 2)),
                [238, 242, 248]
            );
            if ($ratio < 4.5) {
                $failures[] = sprintf('%s: %s -> %s measures %.2f', $channel, $hex, $deep, $ratio);
            }
        }

        $this->assertSame([], $failures, "A channel's deepened accent cannot hold its own button text:\n".implode("\n", $failures));
    }

    private function contrastRatio(array $a, array $b): float
    {
        $luminance = static function (array $rgb): float {
            $channel = static function (int $value): float {
                $v = $value / 255;

                return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            };

            return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
        };

        $l1 = $luminance($a);
        $l2 = $luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    public function test_no_focus_ring_takes_a_channel_colour(): void
    {
        $offenders = [];

        foreach ($this->stylesheets() as $path) {
            $source = File::get($path);
            $key = str_replace(resource_path('css').'/', '', $path);

            foreach (explode('}', $source) as $block) {
                if (! str_contains($block, ':focus-visible') && ! str_contains($block, ':focus')) {
                    continue;
                }
                if (preg_match('/outline(-color)?\s*:[^;]*var\(\s*--ch\b/', $block)) {
                    $offenders[] = $key.' :: '.trim(explode('{', $block)[0]);
                }
            }
        }

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "A focus ring is painted in a channel accent. On the dark ground the darker\n"
            ."channels resolve to about 1.2:1, well under the 3:1 WCAG 1.4.11 requires.\n"
            ."Use --bl-brand, which clears both grounds:\n\n".implode("\n", $offenders)
        );
    }

    public function test_an_overdue_sla_chip_does_not_print_a_broken_sentence(): void
    {
        $overdue = $this->blade(
            '<x-sla-chip :deadline="$d" />',
            ['d' => time() - ((33 * 86400) + (2 * 3600))]
        )->__toString();

        $this->assertStringContainsString('Overdue', $overdue);
        $this->assertStringContainsString('33d 2h', $overdue);

        preg_match('/<span class="sla-chip__label">([^<]*)<\/span>/', $overdue, $m);
        $this->assertNotEmpty($m, 'the chip no longer renders a label span');
        $this->assertSame('Overdue', trim($m[1]));

        $this->assertStringNotContainsString(
            'late',
            strip_tags($overdue),
            'the countdown still carries the word "late" alongside its label'
        );

        $upcoming = $this->blade(
            '<x-sla-chip :deadline="$d" />',
            ['d' => time() + (8 * 86400)]
        )->__toString();
        preg_match('/<span class="sla-chip__label">([^<]*)<\/span>/', $upcoming, $u);
        $this->assertSame('Ship by', trim($u[1]));

        $ticker = File::get(resource_path('js/sla-countdown.js'));
        $this->assertFileExists(resource_path('js/sla-countdown.js'));
        $this->assertStringContainsString(
            'sla-chip__label',
            $ticker,
            'the countdown ticker refreshes the time but never the label, so a chip that '
            .'crosses its deadline with the page open keeps saying "Ship by"'
        );
        $this->assertStringNotContainsString(
            'late`',
            $ticker,
            'the ticker still appends "late" to the countdown'
        );
    }

    public function test_the_focus_ring_clears_three_to_one_in_both_themes(): void
    {
        $components = File::get(resource_path('css/blotter-components.css'));

        $root = File::get(resource_path('css/blotter.css'));

        $rules = [];

        preg_match(
            '/\.fh-scope :focus-visible\s*\{[^}]*outline-color:\s*var\(\s*(--[a-z0-9-]+)/i',
            $components,
            $m
        );
        $this->assertNotEmpty($m, 'the channel-scoped focus ring rule was not found');
        $rules['the channel-scoped ring'] = $m[1];

        preg_match(
            '/\n\s*:focus-visible\s*\{[^}]*outline:\s*[^;]*var\(\s*(--[a-z0-9-]+)/i',
            $root,
            $b
        );
        $this->assertNotEmpty($b, 'the base focus ring rule was not found in blotter.css');
        $rules['the base ring'] = $b[1];

        $themes = [
            'light' => ['ground' => '--bl-paper', 'block' => $this->cssBlock($root, ':root')],
            'dark' => ['ground' => '--bl-paper', 'block' => $this->cssBlock($root, "[data-theme='dark']")],
        ];

        $failures = [];

        foreach ($rules as $where => $token) {
            foreach ($themes as $name => $spec) {
                $ring = $this->cssHex($spec['block'], $token);
                $ground = $this->cssHex($spec['block'], $spec['ground']);

                $this->assertNotNull($ring, "{$token} is not declared as a hex in the {$name} theme");
                $this->assertNotNull($ground, "{$spec['ground']} is not declared as a hex in the {$name} theme");

                $ratio = $this->contrastRatio($ring, $ground);

                if ($ratio < 3.0) {
                    $failures[] = sprintf('%s, %s: %s measures %.2f', $where, $name, $token, $ratio);
                }
            }
        }

        $this->assertSame(
            [],
            $failures,
            "The focus ring is not visible in every theme. WCAG 1.4.11 asks 3:1 of a focus\n"
            ."indicator, and a ring that only works in one theme is not a ring:\n\n".implode("\n", $failures)
        );
    }

    private function cssBlock(string $css, string $selector): string
    {
        $start = strpos($css, $selector.' {');

        if ($start === false) {
            return '';
        }

        $open = strpos($css, '{', $start);
        $close = strpos($css, '}', $open);

        return substr($css, $open, $close - $open);
    }

    private function cssHex(string $block, string $token): ?array
    {
        if (preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-f]{6})/i', $block, $m) !== 1) {
            return null;
        }

        return array_map('hexdec', str_split(ltrim($m[1], '#'), 2));
    }

    public function test_no_fulfilment_panel_stacks_a_command_bar_above_the_rows(): void
    {
        $loose = [];

        foreach ($this->fulfilmentPanels() as $label => $markup) {
            if (! str_contains($markup, 'class="x-cmdbar"')) {
                continue;
            }

            $folded = preg_replace('/<x-ui\.foldout\b.*?<\/x-ui\.foldout>/s', '', $markup);

            if (str_contains((string) $folded, 'class="x-cmdbar"')) {
                $loose[] = $label;
            }
        }

        $this->assertSame([], $loose, sprintf(
            "These panels render a command bar outside a fold: %s\n\n"
            ."The sync form belongs in <x-ui.foldout label=\"Sync\">. Left loose it sits\n"
            ."above every row on every page load, for a control touched a few times a day.",
            implode(', ', $loose)
        ));
    }

    public function test_a_filters_fold_says_how_much_it_is_hiding(): void
    {
        $silent = [];

        foreach ($this->fulfilmentPanels() as $label => $markup) {
            preg_match_all('/<x-ui\.foldout[^>]*label="Filters"[^>]*>/s', $markup, $matches);

            foreach ($matches[0] as $tag) {
                if (! str_contains($tag, ':count=')) {
                    $silent[] = $label;
                }
            }
        }

        $this->assertSame([], $silent, sprintf(
            "These panels fold their filters without a count: %s\n\n"
            ."Bind :count to a tally of the filters that are actually set. Without it a\n"
            ."date left in the fold narrows the list with nothing on screen saying so.",
            implode(', ', $silent)
        ));
    }

    public function test_every_shell_layout_offers_a_bypass_block(): void
    {
        $missing = [];

        foreach (glob(resource_path('views/layouts/*.blade.php')) ?: [] as $layout) {
            $markup = File::get($layout);
            $name = basename($layout, '.blade.php');

            if (! str_contains($markup, '<main')) {
                continue;
            }

            if (! str_contains($markup, 'class="x-skip"')) {
                $missing[] = $name.' (no skip link)';
                continue;
            }

            if (! preg_match('/<a class="x-skip" href="#([\w-]+)"/', $markup, $m)) {
                $missing[] = $name.' (skip link has no fragment target)';
                continue;
            }

            if (! str_contains($markup, 'id="'.$m[1].'"')) {
                $missing[] = $name.' (skip target #'.$m[1].' is not in this layout)';
            }
        }

        $this->assertSame([], $missing, sprintf(
            "These layouts do not offer a working bypass block: %s\n\n"
            ."Add <a class=\"x-skip\" href=\"#main\">Skip to content</a> as the first element\n"
            ."in <body>, and give the content landmark id=\"main\" tabindex=\"-1\".",
            implode(', ', $missing)
        ));
    }

    public function test_the_base_control_edge_is_not_a_hairline_rule(): void
    {
        $css = File::get(resource_path('css/blotter.css'));

        preg_match('/\n    \.x-btn \{(.+?)\n    \}/s', $css, $m);

        $this->assertNotEmpty($m, '.x-btn base rule not found in blotter.css');

        $this->assertStringContainsString('var(--bl-field-edge)', $m[1],
            'the base button border must use the control-edge token, not a hairline rule');
        $this->assertStringNotContainsString('border: 1px solid var(--bl-rule-2)', $m[1]);
    }

    private function fulfilmentPanels(): array
    {
        $panels = array_merge(
            glob(base_path('extensions/*/views/orders/_panel.blade.php')) ?: [],
            glob(base_path('extensions/*/views/orders/_returns_panel.blade.php')) ?: []
        );

        $this->assertNotEmpty($panels, 'no fulfilment panels found to check');

        $out = [];

        foreach ($panels as $panel) {
            $channel = basename(dirname($panel, 3));
            $kind = str_contains(basename($panel), 'returns') ? 'returns' : 'orders';
            $out[$channel.' '.$kind] = File::get($panel);
        }

        return $out;
    }

    private function stylesheets(): array
    {
        return array_map(
            fn ($file) => $file->getPathname(),
            array_filter(
                File::allFiles(resource_path('css')),
                fn ($file) => $file->getExtension() === 'css'
            )
        );
    }
}
