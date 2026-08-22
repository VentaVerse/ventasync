{{-- ============================================================
    User-group permission picker (Phase 2)
    -------------------------------------------------------------
    Renders the permission tree as a list of segmented "pills". A
    row is built per **resource base** (e.g. "lazada", "user_groups"),
    not per individual permission record:

      - If both view_X and manage_X exist for a base → 3-segment pill
      - If only manage_X exists                       → 2-segment pill
      - If only view_X exists                         → 2-segment pill (Off | View)

    Hidden inputs (`permissions[]`) underneath each pill carry the
    actual DB permission IDs. The JS controller toggles each input's
    `disabled` attribute so the submission contract stays identical
    to the legacy checkbox UI. The backend still receives a flat
    array of granted IDs.

    Display labels come from the existing Permission::label accessor,
    which already resolves through the extension's translation
    namespace, so this view never needs to know which extension owns
    a key.
============================================================ --}}
@php
    $selected = $selected ?? [];
    $hierarchy = app(\App\Services\PermissionHierarchy::class);

    // Collapse view_X / manage_X siblings into one logical row per base.
    // Each "row" carries up to two permission records and the pill it should
    // render. Standalone keys (no view/manage prefix, e.g. container parents)
    // are surfaced as a 2-segment pill on the base itself.
    $buildRows = function (array $perms) use ($hierarchy, $selected) {
        $byBase = [];
        $order = [];
        foreach ($perms as $perm) {
            $base = $hierarchy->baseOf($perm->key);
            // Permissions without a view_/manage_ prefix (e.g. a standalone
            // parent like 'manage_marketplace_api') keyed under themselves.
            $bucket = $base ?? $perm->key;
            if (!isset($byBase[$bucket])) {
                $byBase[$bucket] = ['view' => null, 'manage' => null, 'base' => $bucket, 'fallback' => null];
                $order[] = $bucket;
            }
            if (str_starts_with($perm->key, 'view_')) {
                $byBase[$bucket]['view'] = $perm;
            } elseif (str_starts_with($perm->key, 'manage_')) {
                $byBase[$bucket]['manage'] = $perm;
            } else {
                // Container or standalone. Bind it under "manage" so the pill
                // collapses to Off|Manage with this id as the manage tier.
                $byBase[$bucket]['manage'] = $perm;
                $byBase[$bucket]['fallback'] = $perm;
            }
        }
        $rows = [];
        foreach ($order as $base) {
            $entry = $byBase[$base];
            // segCount is 3 when BOTH view + manage exist (renders Off|View|Manage),
            // otherwise 2. The single non-off segment for the 2-seg case carries
            // the tier name of whichever variant actually exists (view OR manage),
            // so a read-only extension key like view_petty_cash_transactions renders
            // as [Off | View], not the impossible [Off | Manage].
            $hasView = (bool) $entry['view'];
            $hasManage = (bool) $entry['manage'];
            $segCount = ($hasView && $hasManage) ? 3 : 2;
            $secondaryTier = $hasManage ? 'manage' : 'view';

            // Initial state from selected[]
            $state = 'off';
            if ($entry['manage'] && in_array($entry['manage']->id, $selected)) {
                $state = 'manage';
            } elseif ($entry['view'] && in_array($entry['view']->id, $selected)) {
                $state = 'view';
            }

            // The "primary" permission record is used purely for the label:
            // prefer manage (since it's the broader scope and most familiar
            // to existing operators).
            $primary = $entry['manage'] ?? $entry['view'];

            $rows[] = [
                'base'          => $base,
                'segments'      => $segCount,
                'secondaryTier' => $secondaryTier,
                'state'         => $state,
                'view'          => $entry['view'],
                'manage'        => $entry['manage'],
                'primary'       => $primary,
            ];
        }
        return $rows;
    };

    // Collect every permission ID that WILL be surfaced as a pill so we know
    // which selected IDs are "orphans" (e.g. top-level container parents like
    // manage_marketplace_api that don't appear as their own row). Orphans are
    // re-emitted as hidden, untouchable inputs so existing saves stay
    // wire-compatible. They are invisible to the operator but survive Save.
    $pillSurfacedIds = [];
    foreach ($permissionGroups as $pg) {
        $kids = $pg['children'] ?? [];
        if (!empty($pg['subgroups'])) {
            foreach ($pg['subgroups'] as $sg) {
                $kids = array_merge($kids, $sg['children']);
            }
        }
        if (empty($kids)) {
            // Standalone group. Its single parent IS the pill.
            $pillSurfacedIds[] = $pg['parent']->id;
        } else {
            foreach ($kids as $perm) {
                $pillSurfacedIds[] = $perm->id;
            }
        }
    }
    /* NOT USED FOR THE ORPHAN TEST ANY MORE - see the recount after the
       collapse below. This walk predicted which ids a pill would surface, the
       collapse then changed the answer, and the two drifted: manage_catalog
       gained a control and was still emitted as an untouchable hidden input,
       so its id would have been submitted twice. The rendered rows are the
       only honest source for "does this permission have a control". */
    $pillSurfacedIds = array_flip($pillSurfacedIds);

    // Walk the tree, build everything we need for render, accumulate totals.
    $renderGroups = [];
    $totalPills = 0;
    $grantedPills = 0;
    foreach ($permissionGroups as $pg) {
        $section = [
            'key'         => $pg['key'],
            'label'       => $pg['label'],
            'parent'      => $pg['parent'],
            'parent_row'  => null,  // shown if the parent itself is a real assignable permission (i.e. standalone)
            'subsections' => [],
            'count_manage' => 0,
            'count_view'   => 0,
        ];

        $childrenFlat = $pg['children'] ?? null;
        $subgroups = $pg['subgroups'] ?? null;

        $isStandalone = empty($childrenFlat) && empty($subgroups);
        if ($isStandalone) {
            // Whole "group" is just the parent (e.g. some extension might
            // declare a single top-level permission). Surface as 1-row pill.
            $section['subsections'][] = [
                'label' => null,
                'rows'  => $buildRows([$pg['parent']]),
            ];
        } else {
            if (!empty($childrenFlat)) {
                $section['subsections'][] = [
                    'label' => null,
                    'rows'  => $buildRows($childrenFlat),
                ];
            }
            if (!empty($subgroups)) {
                foreach ($subgroups as $sg) {
                    $section['subsections'][] = [
                        'label' => $sg['label'],
                        'rows'  => $buildRows($sg['children']),
                    ];
                }
            }
        }

        // Tally for this section and globally
        foreach ($section['subsections'] as &$sub) {
            foreach ($sub['rows'] as $row) {
                $totalPills++;
                if ($row['state'] === 'manage') { $section['count_manage']++; $grantedPills++; }
                elseif ($row['state'] === 'view') { $section['count_view']++; $grantedPills++; }
            }
        }
        unset($sub);

        $renderGroups[] = $section;
    }

    // ---------------------------------------------------------------
    // Collapse twin top-level sections into a single section.
    //
    // Twin pattern: a domain (Warehousing, Catalog, Sales, etc.) has
    // BOTH view_<domain> and manage_<domain> declared at top level
    // (no `parent` field). PermissionHierarchy::compute() treats each
    // as its own group, so the rendering loop above emits two
    // <section> blocks per domain, one with just the parent pill,
    // one with the children.
    //
    // We want a single section per domain so:
    //   - parent and children sit visibly under one header
    //   - data-group-key is the bare base (e.g. "warehousing"), so
    //     the JS Off-cascade can walk siblings within the section
    //     and flip them too
    //
    // Merge rule: any section whose parent->key is view_<X> AND
    // there's another section with parent->key manage_<X> gets
    // folded into the manage_<X> section as a leading row. The
    // manage_<X> section becomes the canonical header (it already
    // owns the children).
    // ---------------------------------------------------------------
    $collapsed = [];
    $byBase = []; // base => index in $collapsed
    foreach ($renderGroups as $section) {
        $parentKey = $section['parent']->key;
        $base = $hierarchy->baseOf($parentKey) ?? $parentKey;
        $isManageParent = str_starts_with($parentKey, 'manage_');

        if (isset($byBase[$base])) {
            $idx = $byBase[$base];
            $existing = $collapsed[$idx];
            $existingParentKey = $existing['parent']->key;
            $existingIsManage = str_starts_with($existingParentKey, 'manage_');

            // Decide which section stays as the host (the manage_<X> one).
            if ($isManageParent && !$existingIsManage) {
                // Current iteration brings the manage_<X> section. Promote it,
                // fold the existing (view_<X>) section into it as a row.
                $host = $section;
                $orphan = $existing;
            } else {
                // Existing section already owns manage_<X>. Fold the current
                // (view_<X>) one into it.
                $host = $existing;
                $orphan = $section;
            }

            /* BOTH TWINS, not just the orphan.
             *
             * This passed only $orphan['parent'] - the view_<X> record - so
             * $buildRows saw one tier and rendered a TWO-segment pill. The
             * row then read "Catalog (View All)  [Off | View]" for a group
             * holding manage_catalog, which is not an incomplete statement
             * but a false one, and there was no control anywhere on the page
             * that could grant manage_catalog in the first place.
             *
             * Thirteen permissions were unreachable this way, every one of
             * them a domain-wide Manage: catalog, sales, marketplace_api,
             * settings, purchasing, warehousing, audit. The Administrator
             * group holds five of them and could not be rebuilt through its
             * own editor. They survived only as hidden carry-over inputs.
             *
             * Handing $buildRows both records lets it do what it already
             * knew how to do: same base, both tiers present, three segments. */
            $parentRow = $buildRows([$orphan['parent'], $host['parent']]);
            array_unshift($host['subsections'], ['label' => null, 'rows' => $parentRow]);

            // Tally the inserted row into the host's counts.
            foreach ($parentRow as $r) {
                $totalPills++;
                if ($r['state'] === 'manage') { $host['count_manage']++; $grantedPills++; }
                elseif ($r['state'] === 'view') { $host['count_view']++; $grantedPills++; }
            }

            // The host's section key becomes the bare base so JS sees the
            // children as siblings of the parent inside this single section.
            $host['key'] = $base;
            $collapsed[$idx] = $host;
        } else {
            // First time we see this base. Normalize key to the bare base so
            // domains that DON'T have a twin (e.g. Purchasing) still match
            // the convention.
            $section['key'] = $base;
            $collapsed[] = $section;
            $byBase[$base] = array_key_last($collapsed);
        }
    }
    $renderGroups = $collapsed;

    /* ONE COUNT, TAKEN FROM WHAT RENDERS.
     *
     * $totalPills was incremented in two places - once per row as each
     * section was built, and again for every twin folded in afterwards - so
     * it ran 7 ahead of the 37 controls on screen. A fully granted
     * Administrator displayed "37 / 44" and data-full could never reach 1,
     * which taught the operator to disbelieve the only progress indicator on
     * the page.
     *
     * Recounting here costs one walk and cannot drift, because it counts the
     * same array the markup below iterates. The per-section tallies keep
     * their own increments; only the global figures are re-derived. */
    /* A SECTION PARENT THAT IS A REAL PERMISSION GETS A ROW, twin or no twin.
     *
     * The collapse above only surfaces a parent when a view_/manage_ pair of
     * SECTIONS exists to fold together. A domain with children and no twin -
     * petty_cash, whose parent is view_petty_cash - fell through it, so the
     * permission was assignable in the database and had no control on the
     * page. Same defect as the twin case, reached by a different route, which
     * is why it is fixed here by asking what is missing rather than by
     * enumerating another special case. */
    foreach ($renderGroups as $i => $section) {
        $parent = $section['parent'] ?? null;

        if (! $parent) {
            continue;
        }

        $alreadyShown = false;

        foreach ($section['subsections'] as $sub) {
            foreach ($sub['rows'] as $row) {
                foreach (['view', 'manage'] as $tier) {
                    if ($row[$tier] && $row[$tier]->id === $parent->id) {
                        $alreadyShown = true;
                    }
                }
            }
        }

        if (! $alreadyShown && ! empty($section['subsections'])) {
            array_unshift(
                $renderGroups[$i]['subsections'],
                ['label' => null, 'rows' => $buildRows([$parent])]
            );
        }
    }

    $totalPills = 0;
    $grantedPills = 0;
    $surfacedIds = [];

    foreach ($renderGroups as $section) {
        foreach ($section['subsections'] as $sub) {
            foreach ($sub['rows'] as $row) {
                $totalPills++;

                if ($row['state'] === 'manage' || $row['state'] === 'view') {
                    $grantedPills++;
                }

                foreach (['view', 'manage'] as $tier) {
                    if ($row[$tier]) {
                        $surfacedIds[$row[$tier]->id] = true;
                    }
                }
            }
        }
    }

    /* What is granted and has no control anywhere. After the fix above this
       is only ever a permission the hierarchy could not place at all - a key
       left behind by a removed feature, say - and it is still carried through
       the save rather than silently revoked. */
    $orphanSelected = array_values(array_filter($selected, fn ($id) => !isset($surfacedIds[$id])));
@endphp

<div id="perm-picker-v2"
     data-total="{{ $totalPills }}"
     data-empty="0">

    {{-- Carry-over hidden inputs for currently-granted IDs that don't have
         their own pill (top-level group/container permissions). These survive
         the save round-trip even though the operator can't toggle them; if
         the operator wants to revoke them too, they must use the "Off all"
         cascade which detaches everything including these. --}}
    @foreach($orphanSelected as $orphanId)
        <input type="hidden" name="permissions[]" value="{{ $orphanId }}" data-orphan="1">
    @endforeach

    {{-- ============================================================
         Sticky toolbar: global counter, cascade buttons, presets, search
         ============================================================ --}}
    <div class="permv2-toolbar">
        <div class="permv2-title">
            <span>Permissions</span>
            <span class="permv2-counter"
                  data-permv2="counter"
                  aria-hidden="true"
                  data-empty="{{ $grantedPills === 0 ? '1' : '0' }}"
                  data-full="{{ $grantedPills === $totalPills ? '1' : '0' }}">
                {{ $grantedPills }} / {{ $totalPills }}
            </span>

            {{-- THE SAME FIGURE, SAID OUT LOUD.

                 The visible counter reads "38 / 38", which is right for the
                 eye and useless in the ear, so it is hidden from assistive
                 technology and this carries the announcement instead. It is
                 also the only announcement the picker makes at all: pressing
                 "Off all" revoked every permission in the group and said
                 nothing, on the screen that decides who can delete a live
                 marketplace listing.

                 role="status" rather than aria-live="assertive": a change
                 here should be heard after the operator's own action is
                 announced, not cut across it. --}}
            <span class="x-sr" role="status" data-permv2="counter-say">
                {{ $grantedPills }} of {{ $totalPills }} areas granted
            </span>
        </div>

        <div class="permv2-actions">
            {{-- data-confirm, because this is the most destructive control
                 on the page and it was the only unguarded one. Deleting an
                 empty group already opens a dialog; revoking every permission
                 from a group three administrators are in did not. The count
                 in the message is refreshed by the script as pills change, so
                 it names what is actually about to go. --}}
            <button type="button" class="permv2-cascade" data-cascade="off"
                    data-confirm="Switch every area off for this group? Everyone in it loses all access until you save something back."
                    title="Set every pill to Off">
                <span class="permv2-dot"></span> Off all
            </button>
            <button type="button" class="permv2-cascade" data-cascade="view" title="Set every pill that supports View to View">
                <span class="permv2-dot"></span> View all
            </button>
            <button type="button" class="permv2-cascade" data-cascade="manage" title="Set every pill to Manage (full control)">
                <span class="permv2-dot"></span> Manage all
            </button>

            <div class="permv2-preset" data-permv2="preset" data-open="0">
                {{-- No aria-haspopup. It defaults to "menu" and this panel is
                     a group of buttons, so it promised a menu and handed over
                     a group - the identical mistake components/ui/menu.blade.php
                     documents. aria-expanded plus aria-controls is the whole
                     contract a disclosure needs. --}}
                <button type="button"
                        id="permv2-preset-trigger"
                        class="permv2-preset-trigger"
                        data-permv2="preset-trigger"
                        aria-controls="permv2-preset-menu"
                        aria-expanded="false">
                    Preset
                </button>
                {{-- NOT role="menu", for the reason components/ui/menu.blade.php
                     already writes down at length: role="menu" promises
                     children with role="menuitem", arrow-key navigation and
                     typeahead, and this is three buttons with none of it. It
                     announced itself as a menu and behaved like a div, which
                     is worse than saying nothing. A group of buttons, named
                     by its trigger, is what it actually is. --}}
                <div class="permv2-preset-menu" id="permv2-preset-menu" data-permv2="preset-menu"
                     role="group" aria-labelledby="permv2-preset-trigger">
                    <button type="button" data-preset="viewer">
                        <span class="permv2-preset-name">Viewer</span>
                        <span class="permv2-preset-hint">Read-only across products, sales, marketplaces. Settings stay locked.</span>
                    </button>
                    <button type="button" data-preset="operator">
                        <span class="permv2-preset-name">Operator</span>
                        <span class="permv2-preset-hint">Full control of day-to-day work. Cannot touch user groups or system settings.</span>
                    </button>
                    <button type="button" data-preset="administrator">
                        <span class="permv2-preset-name">Administrator</span>
                        <span class="permv2-preset-hint">Every permission granted. Equivalent to the built-in Administrator group.</span>
                    </button>
                </div>
            </div>
        </div>

        <div class="permv2-search-wrap">
            <span class="permv2-search-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
            </span>
            <input type="search"
                   class="permv2-search"
                   placeholder="Filter permissions. Press Esc to clear."
                   aria-label="Filter permissions"
                   data-permv2="search">
        </div>
    </div>

    <div class="permv2-footnote">
        <span class="permv2-footnote-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        </span>
        <span>
            <strong>Manage</strong> automatically includes <strong>View</strong>. Use the segmented pills below or
            <kbd>←</kbd> / <kbd>→</kbd> on the keyboard once focused.
        </span>
    </div>

    {{-- ============================================================
         Groups
         ============================================================ --}}
    @foreach($renderGroups as $section)
        {{-- A NAMED REGION, so the grouping is not sighted-only.

             The eight domain sections are the design's best idea and were
             invisible to a screen reader: a bare <section> has no accessible
             name, so it produces no landmark and no grouping, and the whole
             37-row form exposed three headings. Someone navigating by
             structure heard one flat list of pills with no domain boundaries
             anywhere in it. --}}
        <section class="permv2-group"
                 data-group-key="{{ $section['key'] }}"
                 data-collapsed="0"
                 aria-labelledby="permv2-title-{{ $section['key'] }}">
            <button type="button"
                    class="permv2-group-head"
                    aria-expanded="true"
                    aria-controls="permv2-body-{{ $section['key'] }}">
                <span class="permv2-chev" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
                <span class="permv2-group-title">
                    <span class="permv2-group-title-text" id="permv2-title-{{ $section['key'] }}">{{ $section['label'] }}</span>
                    @if($section['parent']->description)
                        <span class="permv2-group-desc">{{ $section['parent']->description }}</span>
                    @endif
                </span>
                <span class="permv2-group-stats">
                    <span class="permv2-stat"
                          data-stat="manage"
                          data-kind="manage"
                          data-zero="{{ $section['count_manage'] === 0 ? '1' : '0' }}">
                        <span class="permv2-stat-pip"></span>
                        <span data-stat-value>{{ $section['count_manage'] }}</span>&nbsp;manage
                    </span>
                    <span class="permv2-stat"
                          data-stat="view"
                          data-kind="view"
                          data-zero="{{ $section['count_view'] === 0 ? '1' : '0' }}">
                        <span class="permv2-stat-pip"></span>
                        <span data-stat-value>{{ $section['count_view'] }}</span>&nbsp;view
                    </span>
                </span>
            </button>

            <div class="permv2-group-body" id="permv2-body-{{ $section['key'] }}">
                @foreach($section['subsections'] as $sub)
                    @php
                        $subId = 'permv2-sub-' . $section['key'] . '-' . $loop->index;
                    @endphp

                    {{-- The channel boundary, said rather than only drawn.

                         Lazada, Shopee, TikTok Shop and the rest were plain
                         <div>s, so twelve marketplace pills arrived as one
                         undivided run with nothing marking where one channel
                         ended and the next began. role="group" is what ties
                         the label to the rows it heads. --}}
                    @if(!empty($sub['label']))
                        <div class="permv2-subgroup" role="group" aria-labelledby="{{ $subId }}">
                        <div class="permv2-subgroup-label" id="{{ $subId }}">{{ $sub['label'] }}</div>
                    @endif

                    @foreach($sub['rows'] as $row)
                        @php
                            $primary = $row['primary'];
                            $base = $row['base'];
                            $label = $primary->label;
                            $desc = $primary->description;
                            $segments = $row['segments'];
                            $state = $row['state'];
                            $pillId = 'permv2-pill-' . str_replace(['.', '/'], '_', $base);
                            $searchHaystack = trim(strtolower($base . ' ' . $label . ' ' . ($desc ?? '')));
                        @endphp

                        <div class="permv2-row"
                             data-hidden="0"
                             data-search="{{ $searchHaystack }}">

                            <div class="permv2-row-info">
                                <div class="permv2-row-label">
                                    <span>{{ $label }}</span>
                                    <span class="permv2-row-base" title="permission key base">{{ $base }}</span>
                                </div>
                                @if($desc)
                                    <div class="permv2-row-desc">{{ $desc }}</div>
                                @endif
                            </div>

                            <div class="permv2-pill"
                                 id="{{ $pillId }}"
                                 role="radiogroup"
                                 aria-label="{{ $label }} permission level"
                                 data-segments="{{ $segments }}"
                                 data-state="{{ $state }}"
                                 data-base="{{ $base }}">
                                <span class="permv2-pill-thumb" aria-hidden="true"></span>

                                <button type="button"
                                        class="permv2-pill-seg"
                                        role="radio"
                                        data-value="off"
                                        aria-checked="{{ $state === 'off' ? 'true' : 'false' }}"
                                        tabindex="{{ $state === 'off' ? '0' : '-1' }}"
                                        title="No access">
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="5" y1="19" x2="19" y2="5"></line></svg>
                                    <span>Off</span>
                                </button>

                                @if($segments === 3)
                                    <button type="button"
                                            class="permv2-pill-seg"
                                            role="radio"
                                            data-value="view"
                                            aria-checked="{{ $state === 'view' ? 'true' : 'false' }}"
                                            tabindex="{{ $state === 'view' ? '0' : '-1' }}"
                                            title="Read-only access">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        <span>View</span>
                                    </button>

                                    <button type="button"
                                            class="permv2-pill-seg"
                                            role="radio"
                                            data-value="manage"
                                            aria-checked="{{ $state === 'manage' ? 'true' : 'false' }}"
                                            tabindex="{{ $state === 'manage' ? '0' : '-1' }}"
                                            title="Full control. Includes view.">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                                        <span>Manage</span>
                                    </button>
                                @elseif($row['secondaryTier'] === 'view')
                                    <button type="button"
                                            class="permv2-pill-seg"
                                            role="radio"
                                            data-value="view"
                                            aria-checked="{{ $state === 'view' ? 'true' : 'false' }}"
                                            tabindex="{{ $state === 'view' ? '0' : '-1' }}"
                                            title="Read-only access (no full-control tier exists for this resource)">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                        <span>View</span>
                                    </button>
                                @else
                                    <button type="button"
                                            class="permv2-pill-seg"
                                            role="radio"
                                            data-value="manage"
                                            aria-checked="{{ $state === 'manage' ? 'true' : 'false' }}"
                                            tabindex="{{ $state === 'manage' ? '0' : '-1' }}"
                                            title="Full control. Includes view.">
                                        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                                        <span>Manage</span>
                                    </button>
                                @endif

                                {{-- Hidden inputs.
                                     - The active tier's input is enabled and submits its DB id.
                                     - The inactive tier (if any) is disabled and is skipped at submit time.
                                     - When state="off" BOTH are disabled and the row contributes nothing
                                       to permissions[], equivalent to the old "unchecked" state. --}}
                                @if($row['view'])
                                    <input type="hidden"
                                           name="permissions[]"
                                           value="{{ $row['view']->id }}"
                                           data-tier="view"
                                           data-perm-key="{{ $row['view']->key }}"
                                           {{ $state === 'view' ? '' : 'disabled' }}>
                                @endif
                                @if($row['manage'])
                                    <input type="hidden"
                                           name="permissions[]"
                                           value="{{ $row['manage']->id }}"
                                           data-tier="manage"
                                           data-perm-key="{{ $row['manage']->key }}"
                                           {{ $state === 'manage' ? '' : 'disabled' }}>
                                @endif
                            </div>
                        </div>
                    @endforeach

                    @if(!empty($sub['label']))
                        </div>
                    @endif
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="permv2-empty" role="status" aria-live="polite">
        <div class="permv2-empty-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
        </div>
        No permissions match your filter.
    </div>
</div>
