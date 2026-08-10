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
        'bl',       // the foundation itself
        'od',       // order pages
        'fm',       // form anatomy
        'fh',       // fulfilment summary
        'cw',       // channel workspace
        'cs',       // channel settings
        'oc',       // opencart
        'lic',      // licences
        'api',      // api explorer
        'pc',       // petty cash
        'inv',      // inventory
        'int',      // integrations hub
        'db',       // dashboard
        'detail',   // shared detail anatomy
    ];

    /**
     * Legacy design tokens still referenced per stylesheet, as of the
     * foundation commit.
     *
     * 60 files, 2,550 references, measured with the checker's own regex over
     * the whole of resources/css rather than over the bridge's import list -
     * which is how the first estimate came out at 20 files and was wrong.
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
        'base/theme.css' => 2,
        'base/tokens.css' => 6,
        'blotter-components.css' => 4,
        'components/alerts.css' => 14,
        'components/buttons.css' => 7,
        'components/cards.css' => 4,
        'components/channel-orders.css' => 108,
        'components/currency-dual.css' => 4,
        'components/currency-rate.css' => 1,
        'components/detail.css' => 21,
        'components/form-page.css' => 226,
        'components/forms.css' => 18,
        'components/inline-input.css' => 6,
        'components/invoice-request.css' => 8,
        'components/modals.css' => 4,
        'components/order-detail.css' => 147,
        'components/order-totals.css' => 2,
        'components/pagination.css' => 9,
        'components/permission-pill.css' => 129,
        'components/segment.css' => 20,
        'components/tabs.css' => 12,
        'components/typeahead.css' => 7,
        'components/ui-controls.css' => 29,
        'components/ui-hub.css' => 27,
        'components/ui-palette.css' => 34,
        'components/ui-table.css' => 38,
        'layout/channel.css' => 17,
        'layout/shell.css' => 78,
        'pages/api-explorer.css' => 13,
        'pages/audit-log.css' => 18,
        'pages/automations.css' => 1,
        'pages/catalog.css' => 17,
        'pages/channel-catalog.css' => 47,
        'pages/channel-listing.css' => 30,
        'pages/channel-settings.css' => 164,
        'pages/dashboard.css' => 95,
        'pages/fulfilment.css' => 54,
        'pages/group-form.css' => 58,
        'pages/integrations.css' => 78,
        'pages/lazada-orders.css' => 44,
        'pages/licenses.css' => 13,
        'pages/marketplace-common.css' => 15,
        'pages/marketplace-returns.css' => 9,
        'pages/opencart-orders.css' => 1,
        'pages/opencart.css' => 37,
        'pages/order-form.css' => 82,
        'pages/pedallion-orders.css' => 7,
        'pages/petty-cash.css' => 85,
        'pages/product-vendors.css' => 43,
        'pages/purchasing.css' => 131,
        'pages/reports.css' => 124,
        'pages/reviews.css' => 66,
        'pages/settings.css' => 124,
        'pages/shopee-orders.css' => 62,
        'pages/tiktok-orders.css' => 11,
        'pages/timeline.css' => 5,
        'pages/toggle-switch.css' => 4,
        'pages/venta-orders.css' => 13,
        'pages/warehousing.css' => 114,
        'utilities.css' => 3,
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
