<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The foundation, guarded.
 *
 * The application has been unified before. Board #5 ran six migration rounds,
 * each internally excellent, and the result was 22 class-prefix families with
 * four section implementations and six page heads, because every round minted
 * its own anatomy and nothing stopped the next one doing the same.
 *
 * So the foundation ships with the thing the previous rounds lacked: a test
 * that fails when it starts coming apart again. Both assertions are RATCHETS -
 * they permit the debt that exists and refuse any addition to it - so they are
 * useful from the day they are written rather than after the sweep finishes.
 *
 * Everything is enumerated off the filesystem rather than from a list of
 * pages, matching the convention RouteNameSweepTest and FinalRoundTest already
 * follow: a harness that has to be remembered is a harness that drifts.
 */
class FoundationTest extends TestCase
{
    /**
     * Prefixes allowed to define a layout primitive.
     *
     * `bl-` is the foundation and is the only one that SHOULD. The rest are
     * recorded debt: each is a family that minted its own section, card or
     * head before the primitives existed, and each entry is deleted as that
     * family is folded onto .bl-sheet / .bl-inset / .bl-head.
     *
     * The list may shrink. It may not grow.
     */
    private const PRIMITIVE_OWNERS = [
        // Paid off by retiring app.css: oc-, lic-, db- and detail- defined
        // their primitives only in stylesheets the legacy bundle loaded.
        'bl',       // the foundation itself
        'od',       // order pages
        'fm',       // form anatomy
        'fh',       // fulfilment summary
        'cw',       // channel workspace
        'cs',       // channel settings
        'api',      // api explorer
        'pc',       // petty cash
        'inv',      // inventory
        'int',      // integrations hub
    ];

    /**
     * The v2.2 token vocabulary, retired 2026-08-18.
     *
     * The ratchet that used to live here (LEGACY_TOKEN_DEBT) went from
     * 60 files and 2,550 references to zero, at which point the alias
     * bridge (blotter-bridge.css) was deleted, exactly as its header
     * promised. What remains is the terminal form of that ratchet: none
     * of these names may be declared or referenced again. Tokens live in
     * the --bl-* namespace, next to Tailwind's own --color-* / --font-*
     * theme keys; a component may still declare its own scoped parameter
     * (--pill-*, --bl-pad overrides), which no stem below collides with.
     */
    private const RETIRED_TOKEN_PATTERN =
        '/(?<![\w-])--(?:canvas|surface(?:-[a-z]+)?|border(?:-[a-z-]+)?|text(?:-[a-z]+)?|ink|muted'
        .'|accent(?:-[a-z]+)?|danger(?:-hover)?|warning|info|success-(?:ink|soft)|chart-(?:money|count)'
        .'|radius-(?:sm|md|lg)|shadow-(?:sm|menu|modal)|transition-fast|font-ui|control-h(?:-sm)?|row-h'
        .'|sidebar-w-new|motion-(?:fast|base)|space-[1-8])(?![\w-])/';

    /**
     * A section, a card and a page head are the foundation's job. A page that
     * needs a new one of those does not need a new vocabulary; it needs
     * .bl-sheet, .bl-inset or .bl-head, or a variant of one of them.
     *
     * This is the assertion that stops a seventh round happening.
     */
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

    /**
     * The reverse direction: an entry that has been paid off must be removed,
     * so the list cannot quietly keep naming families that no longer own
     * anything. Without this the allowlist would only ever grow stale, and a
     * stale allowlist is how the previous rounds' debt went unnoticed.
     */
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

        // bl- is the foundation and is exempt: it stays whether or not it
        // currently happens to define a primitive in a scanned file.
        $stale = array_diff(self::PRIMITIVE_OWNERS, $found, ['bl']);

        $this->assertSame(
            [],
            array_values($stale),
            "These prefixes no longer define any layout primitive and should be\n"
            ."removed from FoundationTest::PRIMITIVE_OWNERS: "
            .implode(', ', $stale)
        );
    }

    /**
     * The retired vocabulary stays retired.
     *
     * The undeclared-property check below cannot catch a revival on its
     * own: re-declare --surface somewhere and every use of it is
     * "declared" again. This is the assertion that keeps the door shut.
     */
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

    /**
     * A page title on a shell page must be the shared one.
     *
     * This is the assertion the first version of this test lacked, and the
     * gap it left is exactly how Products was missed: the guard caught a page
     * inventing a NEW primitive and was blind to a page reimplementing an
     * EXISTING one in raw utilities. Products and the dashboard both rendered
     * their page title as a bare <h1> sized by hand with text-[21px], next to
     * a hand-rolled filter row, while 45 other lists used .x-list-head and
     * .x-filters. No amount of work on the shared components could reach
     * them, because they were not using the shared components.
     *
     * Reimplementation is the commoner failure mode of the two, and the one a
     * reviewer is least likely to spot: the page looks nearly right.
     *
     * Scoped to views that extend a shell layout. Error pages, PDFs, print
     * sheets and mail templates render outside the application chrome and
     * have no business carrying its classes.
     */
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

    /**
     * Views rendered inside the application chrome, keyed by repo-relative
     * path. Read off the @extends line rather than off a list of directories,
     * so an extension's views are covered on the same terms as core's.
     *
     * @return array<string, string>
     */
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

    /**
     * A var() naming a property nothing declares does not fail loudly - it
     * renders unstyled. That is how layouts/standalone once shipped an
     * unreadable first render, and it is the failure mode a token sweep is
     * most likely to cause: rename a property, miss one reference, and a page
     * quietly loses a colour.
     *
     * Generalised from FinalRoundTest's dashboard-only version, which checked
     * one stylesheet against one token file. Both of those files have since
     * been deleted with the legacy bundle; the check now covers everything.
     */
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
            // --tw-* are Tailwind's own internals, declared by its runtime
            // rather than by any file here.
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

    /**
     * Every channel-private class a fulfilment panel emits has a rule.
     *
     * The regression this exists for: pages/opencart-orders.css and
     * pages/pedallion-orders.css were imported through app.css rather than
     * blotter.css, so when the legacy bundle was retired both files went out
     * with it while every other channel's page vocabulary was carried across.
     * Their panels kept emitting oc-col-products, pd-col-date and thirteen
     * more, and those classes matched nothing, so both channels rendered
     * their order tables with no column widths at all - one column taking
     * whatever the browser felt like giving it.
     *
     * Nothing caught it. The undeclared-custom-property guard reads
     * stylesheets and cannot see a class that no stylesheet mentions, and the
     * focus-ring guard that named pedallion-orders.css by path passed on a
     * file that was not there. This checks the other direction: start from
     * what the views actually emit, and require the stylesheets to answer.
     *
     * Scoped to the channel prefixes, because a shared class (x-, co-) is
     * covered by every page that uses it noticing at once, whereas a
     * channel-private one is only ever seen on the one screen.
     */
    /**
     * The phone card reorders itself by data-label, because column counts
     * differ per channel and :nth-child would reorder a different cell on
     * every marketplace. That makes a renamed column header a silent
     * regression: the cell falls back to the default order, the ship-by
     * deadline quietly stops leading the card, and nothing fails. This is
     * the guard that fails instead.
     */
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

        $stale = array_values(array_diff($targeted, array_keys($emitted)));

        $this->assertSame([], $stale, sprintf(
            "The phone card orders cells no panel emits, so they sit at the default position:\n  %s\n"
            ."Either a column was renamed and marketplace-common.css was not, or the rule is dead.\n"
            .'Labels the panels do emit: %s',
            implode("\n  ", $stale),
            implode(', ', array_keys($emitted))
        ));
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

        // Prefixes that belong to the shared vocabularies rather than to one
        // channel, plus the state prefixes the JS toggles.
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

    /**
     * Every page stylesheet on disk is in the bundle.
     *
     * The companion to the guard above, for the opposite failure: a file that
     * still exists and still reads correctly, but that nothing imports, so
     * none of it reaches a page. The undeclared-property guard walks the
     * filesystem rather than the import list on purpose, which means an
     * unimported file passes every check here while being dead.
     */
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

    /**
     * The accent generator measures against the ink the UI actually paints.
     *
     * ChannelAccent::deep() walks a channel's colour down until it clears
     * 4.5:1 against LIGHT_INK_ON_ACCENT, and the primary button it produces
     * carries --bl-paper. Those two have to be the same colour or the
     * guarantee is computed against something nobody sees: they were not, and
     * the walk stopped at Shopee's #d14428 because that clears 4.597 against
     * pure white, while the button it made measured 4.09 against the paper it
     * was really printed in.
     *
     * A comment claimed this test existed before this test existed. It does
     * now, because the same class of drift - a stated ratio nobody re-measured
     * - had also left segment.css asserting 4.59:1 for a pairing that shipped
     * at 4.21.
     */
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

    /**
     * Every channel's deep accent actually clears the floor it claims.
     *
     * The generator is only as good as its reference colour, and the previous
     * reference was wrong, so this asserts the outcome rather than the method:
     * every accent the shipped extensions declare, deepened, must hold
     * --bl-paper at 4.5:1.
     */
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

        // Plus the ones the critique measured directly, so the guard still
        // bites if a manifest stops declaring its colour.
        $accents += [
            'shopee' => '#ee4d2d',
            'lazada' => '#0f146d',
            'tiktok' => '#1e1e1e',
            'venta'  => '#7c3aed',
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

    /**
     * @param  array<int, int>  $a
     * @param  array<int, int>  $b
     */
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

    /**
     * A focus ring is never painted in a channel's own colour.
     *
     * The channel repaint gave `:focus-visible` inside `.fh-scope` the raw
     * `--ch` hue. Half the channels happened to pass and half did not: on the
     * dark ground Lazada's navy measures 1.22:1 and TikTok's near-black
     * 1.15:1, against the 3:1 WCAG 1.4.11 asks of a focus indicator. It
     * survived review because the theme it failed in was not the theme it was
     * reviewed in.
     *
     * `--bl-brand` owns this job precisely so it does not depend on which
     * channel is open - DESIGN.md assigns it to "focus rings, active rows",
     * and it clears both grounds. This asserts no stylesheet hands the ring
     * back to a per-channel property.
     */
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

    /**
     * The focus ring is visible in BOTH themes.
     *
     * The name-only guard above was not enough, and this test exists because
     * of what it let through. The ring was moved off the channel accent and
     * onto --bl-brand, which measures 8.40:1 on the dark ground and 2.03:1 on
     * the light one - so a fix for the theme that had been measured broke the
     * theme that had not, and the guard passed because the token name was
     * right.
     *
     * A focus indicator needs 3:1 against what it sits on (WCAG 1.4.11). This
     * resolves whatever token the channel-scoped rule actually names, in each
     * theme's own declaration, and measures it against that theme's ground.
     */
    public function test_the_focus_ring_clears_three_to_one_in_both_themes(): void
    {
        $components = File::get(resource_path('css/blotter-components.css'));

        preg_match(
            '/\.fh-scope :focus-visible\s*\{[^}]*outline-color:\s*var\(\s*(--[a-z0-9-]+)/i',
            $components,
            $m
        );

        $this->assertNotEmpty($m, 'the channel-scoped focus ring rule was not found');
        $token = $m[1];

        $root = File::get(resource_path('css/blotter.css'));

        // Each theme declares its own value for the same token; the ring is
        // only as good as the weaker of the two.
        $themes = [
            'light' => ['ground' => '--bl-paper', 'block' => $this->cssBlock($root, ':root')],
            'dark' => ['ground' => '--bl-paper', 'block' => $this->cssBlock($root, "[data-theme='dark']")],
        ];

        $failures = [];

        foreach ($themes as $name => $spec) {
            $ring = $this->cssHex($spec['block'], $token);
            $ground = $this->cssHex($spec['block'], $spec['ground']);

            $this->assertNotNull($ring, "{$token} is not declared as a hex in the {$name} theme");
            $this->assertNotNull($ground, "{$spec['ground']} is not declared as a hex in the {$name} theme");

            $ratio = $this->contrastRatio($ring, $ground);

            if ($ratio < 3.0) {
                $failures[] = sprintf('%s: %s on the ground measures %.2f', $name, $token, $ratio);
            }
        }

        $this->assertSame(
            [],
            $failures,
            "The focus ring is not visible in every theme. WCAG 1.4.11 asks 3:1 of a focus\n"
            ."indicator, and a ring that only works in one theme is not a ring:\n\n".implode("\n", $failures)
        );
    }

    /**
     * The body of the first rule with this exact selector.
     */
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

    /**
     * @return array<int, int>|null
     */
    private function cssHex(string $block, string $token): ?array
    {
        if (preg_match('/'.preg_quote($token, '/').':\s*(#[0-9a-f]{6})/i', $block, $m) !== 1) {
            return null;
        }

        return array_map('hexdec', str_split(ltrim($m[1], '#'), 2));
    }

    /**
     * Every stylesheet that reaches a served page.
     *
     * Read off the filesystem rather than off the @import lists, so a file
     * added to the bundle tomorrow is scanned without anyone remembering to
     * add it here.
     *
     * @return array<int, string>
     */
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
