<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

/**
 * The breadcrumb trail, derived from the URL.
 *
 * IA migration Task 5 (docs/superpowers/plans/2026-08-08-ia-migration.md),
 * spec decision 7: "breadcrumbs from URL segments resolved through
 * Navigation's labels, active state by longest URL prefix". Tasks 1-4 made
 * every URI express the hierarchy, so the address bar is now the only input
 * this needs. There is deliberately NO third registry here: every label comes
 * from somewhere that already exists and is already tested.
 *
 *   - the section segment  -> the owning group's label in Navigation::definition()
 *   - the entity segment   -> that group's item label, linked to its own URL
 *   - a channel workspace  -> the composer payload AppServiceProvider already
 *                             builds for layouts.channel (channel name, store
 *                             label), because /integrations/{channel} names a
 *                             seller center, not a nav section
 *   - the leaf             -> the page's own @section('breadcrumb'), unchanged
 *
 * The legacy layouts/app.blade.php and its 29-entry $__bcRoutes table were
 * PROTECTED and untouched when this class was written; both were deleted in
 * Round F Task 4 once the last view left that layout. This is now the only
 * breadcrumb source in the app, and it still holds no route-name map of its
 * own - which is why the one defect that table carried (an 'Activity Log'
 * entry naming a route that does not exist) did not have to be carried over.
 */
class Breadcrumbs
{
    /**
     * The root crumb of every channel workspace trail. Not a nav label: the
     * Channels nav group is called "Channels", but the workspace root has read
     * "Integrations" since the channel layout was written, it matches the
     * /integrations URL segment, and Task 5 is explicitly not allowed to
     * change the rendered shape of that trail.
     */
    public const CHANNEL_ROOT = 'Integrations';

    /**
     * The trail for a request, outermost crumb first, leaf last.
     *
     * A null `url` means "render as plain text": either the crumb has no
     * destination of its own (a nav GROUP is a heading, not a page), or its
     * destination is the page the operator is already standing on. The last
     * crumb with a null url is the current page; both layouts mark it.
     *
     * @return array<int, array{label: string, url: string|null}>
     */
    public static function for(Request $request): array
    {
        $path = Navigation::normalisePath($request->path());

        $trail = static::channelTrail($request) ?? static::navTrail($path);

        $leaf = static::leaf();

        if ($leaf !== null) {
            $trail[] = ['label' => $leaf, 'url' => null];
        }

        // Never link the page you are standing on. Without this, /settings
        // would render its own "Settings" crumb as a link back to /settings,
        // and /sales/orders its own "Orders" crumb - a control that looks
        // like navigation and does nothing.
        foreach ($trail as $i => $crumb) {
            if ($crumb['url'] !== null && Navigation::normalisePath($crumb['url']) === $path) {
                $trail[$i]['url'] = null;
            }
        }

        return $trail;
    }

    /**
     * Integrations / Channel [/ Store], for any page inside a seller center.
     *
     * Read from the memo AppServiceProvider's layouts.channel composer sets on
     * the request, which is the single computation of "which channel and which
     * store is this" for the whole request - the sidebar head, the page title
     * and this trail all read that one payload rather than each resolving the
     * workspace key again.
     *
     * A channel workspace is NOT resolved through the nav tree on purpose. The
     * Channels group carries one entry per converted channel, but a channel's
     * INNER pages (/integrations/venta/2/orders) belong to that channel's own
     * sidebar, not to the global nav, and a multi-store channel's trail has to
     * name the store, which no nav entry knows how to do from the URL alone.
     *
     * Every crumb here is unlinked, matching the shape this trail has always
     * had: inside a seller center the workspace sidebar is the way back out,
     * and Task 5 freezes the rendered shape of this trail.
     *
     * @return array<int, array{label: string, url: string|null}>|null
     */
    protected static function channelTrail(Request $request): ?array
    {
        $payload = $request->attributes->get('channel-workspace.compose');

        if (! is_array($payload) || ! isset($payload['channelCard'])) {
            return null;
        }

        $trail = [
            ['label' => static::CHANNEL_ROOT, 'url' => null],
            ['label' => (string) $payload['channelCard']->name, 'url' => null],
        ];

        $store = $payload['channelStoreLabel'] ?? null;

        if (is_string($store) && $store !== '') {
            $trail[] = ['label' => $store, 'url' => null];
        }

        return $trail;
    }

    /**
     * Group + item, resolved by longest URL prefix through the nav tree.
     *
     * When no entry covers the path at all, the section segment can still
     * name a group on its own (/catalog/something-not-in-the-nav still stands
     * inside Catalogue), which is better than a trail that vanishes entirely
     * on a page one level too deep.
     *
     * @return array<int, array{label: string, url: string|null}>
     */
    protected static function navTrail(string $path): array
    {
        $match = Navigation::matchPath($path);

        if ($match !== null) {
            $trail = [];

            if ($match['group'] !== null) {
                $trail[] = [
                    'label' => $match['group']['label'],
                    'url' => Navigation::urlFor($match['group']),
                ];
            }

            $trail[] = [
                'label' => $match['item']['label'],
                'url' => Navigation::urlFor($match['item']),
            ];

            return $trail;
        }

        $section = static::firstSegment($path);
        $group = $section === null ? null : Navigation::groupForSection($section);

        if ($group === null) {
            return [];
        }

        return [['label' => $group['label'], 'url' => Navigation::urlFor($group)]];
    }

    /** The first path segment, or null at the root. */
    protected static function firstSegment(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', $path), fn ($s) => $s !== ''));

        return $segments[0] ?? null;
    }

    /**
     * The page's own leaf crumb, or null when it supplies none.
     *
     * @section('breadcrumb') stays the leaf mechanism, so this reads the
     * rendered section straight off the view factory - the same thing
     * layouts/channel.blade.php already did inline before this class existed.
     * Reading a section does not consume it.
     *
     * Returned as plain text, never markup: both layouts print it through
     * Blade's {{ }} escaping. Leaves are order numbers and product-group names
     * that came out of a marketplace API, so they are untrusted strings.
     *
     * Every page above sets its leaf with the inline `@section('breadcrumb',
     * $value)` form, and Illuminate\View\Concerns\ManagesLayouts::
     * startSection() runs e($content) on that value before storing it - it is
     * not a raw capture the way `@section(...) ... @endsection` would be.
     * View::yieldContent() therefore hands back text already HTML-escaped
     * once, and the two layouts' own {{ }} would escape it a second time,
     * turning "Tools & Equipment" into the literal string "Tools &amp;amp;
     * Equipment" on screen (caught here, Round B Task 3, on a category named
     * "Tents & Awnings <b>Sale</b>"; verified the same category/products/edit
     * shipped from Task 2 double-encodes a product named "Tools & Equipment"
     * identically). html_entity_decode() undoes exactly that one layer,
     * restoring the "plain text" contract this docblock already promises, so
     * the layouts' {{ }} is the only escaping that actually reaches the page.
     */
    protected static function leaf(): ?string
    {
        if (! View::getFacadeRoot()) {
            return null;
        }

        $leaf = trim(View::yieldContent('breadcrumb'));
        $leaf = html_entity_decode($leaf, ENT_QUOTES, 'UTF-8');

        return $leaf === '' ? null : $leaf;
    }
}
