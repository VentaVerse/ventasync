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
     * Legacy design tokens still referenced per stylesheet, as of the
     * foundation commit.
     *
     * Was 60 files and 2,550 references. Retiring app.css took 34 stylesheets
     * with it - they were duplicates of the live vocabulary that only the
     * legacy bundle loaded - so most of that debt turned out to be dead code
     * rather than migration work.
     *
     * Most of these reach the live bundle through blotter-bridge.css, which
     * aliases 35 v2.2 tokens onto v2.3 values. They therefore inherit the
     * colours and none of the decisions: not the spacing rhythm, not the 16px
     * sheet radius, not the two-step elevation. The bridge's own header calls
     * itself "a staging post, not a destination".
     *
     * Every number here must go to zero, at which point the bridge is deleted
     * and this whole constant with it.
     */
    private const LEGACY_TOKEN_DEBT = [
        'blotter-components.css' => 4,
        'components/currency-dual.css' => 4,
        'components/currency-rate.css' => 1,
        'components/inline-input.css' => 6,
        'components/invoice-request.css' => 8,
        'components/permission-pill.css' => 129,
        'components/segment.css' => 20,
        'components/tabs.css' => 12,
        'components/typeahead.css' => 7,
        'pages/api-explorer.css' => 13,
        'pages/automations.css' => 1,
        'pages/channel-catalog.css' => 47,
        'pages/channel-listing.css' => 30,
        'pages/channel-settings.css' => 164,
        'pages/group-form.css' => 58,
        'pages/integrations.css' => 75,
        'pages/lazada-orders.css' => 44,
        'pages/licenses.css' => 13,
        'pages/marketplace-common.css' => 15,
        'pages/marketplace-returns.css' => 9,
        'pages/order-form.css' => 82,
        'pages/petty-cash.css' => 85,
        'pages/product-vendors.css' => 43,
        'pages/purchasing.css' => 131,
        'pages/reports.css' => 124,
        'pages/reviews.css' => 66,
        'pages/shopee-orders.css' => 62,
        'pages/tiktok-orders.css' => 11,
        'pages/venta-orders.css' => 13,
        'pages/warehousing.css' => 111,
    ];

    private const LEGACY_TOKEN_PATTERN =
        '/var\(--(space-|surface|border\b|border-|text-|radius-|muted|ink\b|accent)/';

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
     * Legacy token usage may fall. It may not rise, and no stylesheet outside
     * the recorded debt may start using them at all.
     */
    public function test_legacy_token_debt_only_shrinks(): void
    {
        $regressions = [];
        $paid = [];

        foreach ($this->stylesheets() as $path) {
            $key = str_replace(resource_path('css').'/', '', $path);

            // blotter-bridge.css is where the aliases are DECLARED. It is the
            // thing being retired, not a consumer of it.
            if ($key === 'blotter-bridge.css') {
                continue;
            }

            $count = preg_match_all(self::LEGACY_TOKEN_PATTERN, File::get($path));
            $allowed = self::LEGACY_TOKEN_DEBT[$key] ?? 0;

            if ($count > $allowed) {
                $regressions[] = "{$key}: {$count} legacy tokens, {$allowed} allowed";
            }

            if ($count < $allowed) {
                $paid[] = "{$key}: down to {$count} from {$allowed}";
            }
        }

        $this->assertSame(
            [],
            $regressions,
            "Legacy design tokens were added to a stylesheet.\n"
            ."Use the --bl-* tokens: --bl-sheet, --bl-rule, --bl-ink, --bl-r-panel,\n"
            ."--bl-lift and the rest, listed at the top of blotter.css.\n\n"
            .implode("\n", $regressions)
        );

        // Not a failure, but the baseline must be lowered when debt is paid,
        // or the ratchet stops ratcheting.
        $this->assertSame(
            [],
            $paid,
            "Legacy token debt was paid down. Lower these entries in\n"
            ."FoundationTest::LEGACY_TOKEN_DEBT so the ratchet holds the new floor\n"
            ."(delete the entry entirely when it reaches zero):\n\n"
            .implode("\n", $paid)
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
