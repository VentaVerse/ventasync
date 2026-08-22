{{-- What a group may do, one row per area of the application.

     REBUILT ON THE ROUTE TABLE. The version this replaces walked a
     hand-kept parent/child tree out of config/permissions.php, which is why
     it needed twin collapsing, orphan carry-over and a parent-surfacing pass
     to show what the database held - and still missed thirteen keys, every
     domain-wide Manage among them. There is no tree now: an area is a folder
     of controllers, a row is one controller, and both come from the routes.
     See App\Services\PermissionCatalogue.

     The control is unchanged, deliberately. Off / View / Manage in one pill
     is the right primitive for a schema whose tiers are view_ and manage_,
     and it is already accessible - roving tabindex, arrow keys, a named
     radiogroup per row. Only what feeds it has changed. --}}

@php
    $canManage = $canManage ?? true;

    $totalRows = 0;
    $grantedRows = 0;

    foreach ($permissionGroups as $g) {
        foreach ($g['rows'] as $r) {
            $totalRows++;
            if ($r['state'] !== 'off') $grantedRows++;
        }
    }
@endphp

<div id="perm-picker-v2" data-total="{{ $totalRows }}" data-empty="0">

    <div class="permv2-toolbar">
        <div class="permv2-title">
            <span>Permissions</span>
            <span class="permv2-counter"
                  data-permv2="counter"
                  aria-hidden="true"
                  data-empty="{{ $grantedRows === 0 ? '1' : '0' }}"
                  data-full="{{ $grantedRows === $totalRows ? '1' : '0' }}">
                {{ $grantedRows }} / {{ $totalRows }}
            </span>

            {{-- The figure, said aloud. The visible one reads "51 / 51",
                 which is right for the eye and useless in the ear. --}}
            <span class="x-sr" role="status" data-permv2="counter-say">
                {{ $grantedRows }} of {{ $totalRows }} areas granted
            </span>
        </div>

        @if($canManage)
        <div class="permv2-actions">
            <button type="button" class="permv2-cascade" data-cascade="off"
                    data-confirm="Switch every area off for this group? Everyone in it loses all access until you save something back."
                    title="Set every area to Off">
                <span class="permv2-dot"></span> Off all
            </button>
            <button type="button" class="permv2-cascade" data-cascade="view" title="Set every area to View">
                <span class="permv2-dot"></span> View all
            </button>
            <button type="button" class="permv2-cascade" data-cascade="manage" title="Set every area to Manage">
                <span class="permv2-dot"></span> Manage all
            </button>

            {{-- Not role="menu": that promises menuitem children and arrow-key
                 navigation this does not provide. A group of buttons named by
                 its trigger is what it actually is - the same call
                 components/ui/menu.blade.php documents. --}}
            <div class="permv2-preset" data-permv2="preset" data-open="0">
                <button type="button"
                        id="permv2-preset-trigger"
                        class="permv2-preset-trigger"
                        data-permv2="preset-trigger"
                        aria-controls="permv2-preset-menu"
                        aria-expanded="false">
                    Preset
                </button>
                <div class="permv2-preset-menu" id="permv2-preset-menu" data-permv2="preset-menu"
                     role="group" aria-labelledby="permv2-preset-trigger">
                    <button type="button" data-preset="viewer">
                        <span class="permv2-preset-name">Viewer</span>
                        <span class="permv2-preset-hint">Read every area. Settings stay off.</span>
                    </button>
                    <button type="button" data-preset="operator">
                        <span class="permv2-preset-name">Operator</span>
                        <span class="permv2-preset-hint">Full control of day-to-day work. Settings stay off.</span>
                    </button>
                    <button type="button" data-preset="administrator">
                        {{-- True now. It used to claim parity with the built-in
                             Administrator group while granting 37 of its 79,
                             because the domain-wide Manage keys had no control. --}}
                        <span class="permv2-preset-name">Administrator</span>
                        <span class="permv2-preset-hint">Manage every area, settings included.</span>
                    </button>
                </div>
            </div>
        </div>
        @endif
    </div>

    <div class="permv2-search-wrap">
        <x-ui.icon name="search" size="14" class="permv2-search-icon" />
        <input type="search" class="permv2-search" data-permv2="search"
               placeholder="Filter permissions. Press Esc to clear."
               aria-label="Filter permissions">
    </div>

    <p class="permv2-footnote">
        <x-ui.icon name="info" size="13" class="permv2-footnote-icon" />
        <span><b>Manage</b> includes <b>View</b>. An area with nothing to change offers
        <b>View</b> only. Use the segmented controls, or the left and right arrow keys once one has focus.</span>
    </p>

    <div class="permv2-empty" data-permv2="empty" role="status">Nothing matches that filter.</div>

    @foreach($permissionGroups as $group)
        @php
            $manageCount = collect($group['rows'])->where('state', 'manage')->count();
            $viewCount   = collect($group['rows'])->where('state', 'view')->count();
        @endphp

        {{-- A named region, so the grouping is not sighted-only: a bare
             <section> has no accessible name and produces no landmark. --}}
        <section class="permv2-group"
                 data-group-key="{{ $group['area'] }}"
                 data-collapsed="0"
                 aria-labelledby="permv2-title-{{ $group['area'] }}">
            <button type="button"
                    class="permv2-group-head"
                    aria-expanded="true"
                    aria-controls="permv2-body-{{ $group['area'] }}">
                <span class="permv2-chev" aria-hidden="true">
                    <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </span>
                <span class="permv2-group-title">
                    <span class="permv2-group-title-text" id="permv2-title-{{ $group['area'] }}">{{ $group['label'] }}</span>
                </span>
                <span class="permv2-group-stats">
                    <span class="permv2-stat" data-stat="manage" data-kind="manage" data-zero="{{ $manageCount === 0 ? '1' : '0' }}">
                        <span class="permv2-stat-pip"></span>
                        <span data-stat-value>{{ $manageCount }}</span>&nbsp;manage
                    </span>
                    <span class="permv2-stat" data-stat="view" data-kind="view" data-zero="{{ $viewCount === 0 ? '1' : '0' }}">
                        <span class="permv2-stat-pip"></span>
                        <span data-stat-value>{{ $viewCount }}</span>&nbsp;view
                    </span>
                </span>
            </button>

            <div class="permv2-group-body" id="permv2-body-{{ $group['area'] }}">
                @foreach($group['rows'] as $row)
                    @php
                        $key = $row['key'];
                        $pillId = 'permv2-pill-' . str_replace(['/', '.'], '-', $key);
                        $state = $row['state'];
                        $haystack = strtolower($group['label'] . ' ' . $row['label'] . ' ' . $key);
                    @endphp

                    <div class="permv2-row" data-hidden="0" data-search="{{ $haystack }}">
                        <div class="permv2-row-info">
                            <div class="permv2-row-label">
                                <span>{{ $row['label'] }}</span>
                                {{-- The key, always shown. It is what the ledger
                                     records and what a support question quotes,
                                     and it is the one label that cannot go stale. --}}
                                <span class="permv2-row-base" title="permission key">{{ $key }}</span>
                            </div>
                            {{-- Built in PHP rather than inline directives: Blade
                                 matches a directive with \B@, so an @endif sitting
                                 straight after a word character is not recognised at
                                 all and the @if it should close silently runs on. --}}
                            @php
                                $facts = $row['routes'] . ' ' . \Illuminate\Support\Str::plural('action', $row['routes']);
                                if ($row['writes'] === 0) {
                                    $facts .= ', all read-only';
                                }
                            @endphp
                            <div class="permv2-row-desc">{{ $facts }}</div>
                        </div>

                        <div class="permv2-pill"
                             id="{{ $pillId }}"
                             role="radiogroup"
                             aria-label="{{ $row['label'] }} permission level"
                             data-segments="{{ $row['segments'] }}"
                             data-state="{{ $state }}"
                             data-base="{{ $key }}">
                            <span class="permv2-pill-thumb" aria-hidden="true"></span>

                            <button type="button" class="permv2-pill-seg" role="radio"
                                    data-value="off"
                                    aria-checked="{{ $state === 'off' ? 'true' : 'false' }}"
                                    tabindex="{{ $state === 'off' ? '0' : '-1' }}"
                                    title="No access" @disabled(!$canManage)>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"></circle><line x1="5" y1="19" x2="19" y2="5"></line></svg>
                                <span>Off</span>
                            </button>

                            <button type="button" class="permv2-pill-seg" role="radio"
                                    data-value="view"
                                    aria-checked="{{ $state === 'view' ? 'true' : 'false' }}"
                                    tabindex="{{ $state === 'view' ? '0' : '-1' }}"
                                    title="Read, but not change" @disabled(!$canManage)>
                                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                                <span>View</span>
                            </button>

                            @if($row['segments'] === 3)
                                <button type="button" class="permv2-pill-seg" role="radio"
                                        data-value="manage"
                                        aria-checked="{{ $state === 'manage' ? 'true' : 'false' }}"
                                        tabindex="{{ $state === 'manage' ? '0' : '-1' }}"
                                        title="Full control. Includes view." @disabled(!$canManage)>
                                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"></path></svg>
                                    <span>Manage</span>
                                </button>
                            @endif

                            {{-- The active tier's input submits its id; the other is
                                 disabled and skipped. Off disables both, so the row
                                 contributes nothing to permissions[]. --}}
                            @if($row['view'])
                                <input type="hidden" name="permissions[]" value="{{ $row['view']->id }}"
                                       data-tier="view" data-perm-key="{{ $row['view']->key }}"
                                       {{ $state === 'view' ? '' : 'disabled' }}>
                            @endif
                            @if($row['manage'])
                                <input type="hidden" name="permissions[]" value="{{ $row['manage']->id }}"
                                       data-tier="manage" data-perm-key="{{ $row['manage']->key }}"
                                       {{ $state === 'manage' ? '' : 'disabled' }}>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</div>
