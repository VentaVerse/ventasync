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

    $warnRank = ['settings/user_group', 'settings/user', 'settings/api_client', 'settings/extension'];

    $warnedAll = collect($permissionGroups)
        ->flatMap(fn ($g) => collect($g['rows'])->filter(fn ($r) => $r['warning'] !== '')
            ->map(fn ($r) => [
                'key' => $r['key'],
                'name' => $g['label'] === $r['label'] ? $g['label'] : $g['label'] . ' ' . $r['label'],
            ]))
        ->sortBy(fn ($e) => array_search($e['key'], $warnRank) === false
            ? 99
            : array_search($e['key'], $warnRank))
        ->values();

    $warnedNames = $warnedAll->take(3)->pluck('name')->implode(', ');

    if ($warnedAll->count() > 3) {
        $warnedNames .= ' and ' . ($warnedAll->count() - 3) . ' more';
    }
@endphp

<div id="perm-picker-v2" data-total="{{ $totalRows }}" data-empty="0"
     data-warned-names="{{ $warnedNames }}">

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
            <button type="button" class="permv2-cascade" data-cascade="view"
                    data-confirm-tone="primary"
                    data-confirm-count="Give this group View on :n? :c would change."
                    title="Set every area to View">
                <span class="permv2-dot"></span> View all
            </button>
            <button type="button" class="permv2-cascade" data-cascade="manage"
                    data-confirm-tone="primary"
                    data-confirm-count="Give this group Manage on :n? :c would change.:e"
                    title="Set every area to Manage">
                <span class="permv2-dot"></span> Manage all
            </button>

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
                    <button type="button" data-preset="viewer"
                            data-confirm-tone="primary"
                            data-confirm-count="Apply the Viewer preset? :c would change. Presets replace every area, including any hidden by your filter.">
                        <span class="permv2-preset-name">Viewer</span>
                        <span class="permv2-preset-hint">Read every area. Settings stay off.</span>
                    </button>
                    <button type="button" data-preset="operator"
                            data-confirm-tone="primary"
                            data-confirm-count="Apply the Operator preset? :c would change. Presets replace every area, including any hidden by your filter.">
                        <span class="permv2-preset-name">Operator</span>
                        <span class="permv2-preset-hint">Full control of day-to-day work. Settings stay off.</span>
                    </button>
                    <button type="button" data-preset="administrator"
                            data-confirm-tone="primary"
                            data-confirm-count="Apply the Administrator preset? :c would change. It grants Manage on all {{ $totalRows }} areas, filtered or not.:e">
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


    <p class="permv2-note" data-permv2="why" role="status" hidden></p>

    <div class="permv2-empty" data-permv2="empty" role="status">Nothing matches that filter.</div>

    @if($canManage)
        <p class="permv2-note" data-permv2="zerohint" @if($grantedRows !== 0) hidden @endif>
            Nothing granted yet. The Preset menu is the fastest start; the filter finds any single area.
        </p>
    @endif

    @foreach($permissionGroups as $group)
        @php
            $manageCount = collect($group['rows'])->where('state', 'manage')->count();
            $viewCount   = collect($group['rows'])->where('state', 'view')->count();
        @endphp

        @php
            $collapsed = ! empty($group['machine']);
        @endphp

        <section class="permv2-group"
                 data-group-key="{{ $group['area'] }}"
                 data-collapsed="{{ $collapsed ? '1' : '0' }}"
                 @if(! empty($group['machine'])) data-machine="1" @endif
                 aria-labelledby="permv2-title-{{ $group['area'] }}">
            <div class="permv2-group-head">
                <button type="button"
                        class="permv2-group-toggle"
                        aria-expanded="{{ $collapsed ? 'false' : 'true' }}"
                        aria-controls="permv2-body-{{ $group['area'] }}">
                    <span class="permv2-chev" aria-hidden="true">
                        <svg viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"></polyline></svg>
                    </span>
                    <span class="permv2-group-title">
                        <span class="permv2-group-title-text" id="permv2-title-{{ $group['area'] }}">{{ $group['label'] }}</span>
                        @if($group['note'])
                            <span class="permv2-group-note">{{ $group['note'] }}</span>
                        @endif
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

                @if($canManage)
                    @php
                        $groupWarned = collect($group['rows'])
                            ->filter(fn ($r) => $r['warning'] !== '')
                            ->map(fn ($r) => $r['label'])->implode(', ');
                    @endphp
                    <span class="permv2-group-set" role="group" aria-label="Set every {{ $group['label'] }} row">
                        <button type="button" data-groupset="off" title="Every {{ $group['label'] }} row to Off"
                                data-confirm="Switch every {{ $group['label'] }} row off for this group?">Off</button>
                        <button type="button" data-groupset="view" title="Every {{ $group['label'] }} row to View"
                                @if($groupWarned !== '') data-confirm="Give this group View on every {{ $group['label'] }} row? That includes {{ $groupWarned }}." data-confirm-tone="primary" @endif>View</button>
                        <button type="button" data-groupset="manage" title="Every {{ $group['label'] }} row to Manage"
                                @if($groupWarned !== '') data-confirm="Give this group Manage on every {{ $group['label'] }} row? That includes {{ $groupWarned }} - read the note under it first." data-confirm-tone="primary" @endif>Manage</button>
                    </span>
                @endif
            </div>

            <div class="permv2-group-body" id="permv2-body-{{ $group['area'] }}">
                @foreach($group['rows'] as $row)
                    @php
                        $key = $row['key'];
                        $pillId = 'permv2-pill-' . str_replace(['/', '.'], '-', $key);
                        $state = $row['state'];
                        $haystack = strtolower($group['label'] . ' ' . $row['label'] . ' ' . $key);
                    @endphp

                    <div class="permv2-row" data-hidden="0" data-search="{{ $haystack }}"
                         @if($row['warning'] !== '') data-warn="1" @endif>
                        <div class="permv2-row-info">
                            <div class="permv2-row-label">
                                <span>{{ $row['label'] }}</span>
                                <span class="permv2-row-base" title="permission key">{{ $key }}</span>
                            </div>
                            @if($row['warning'] !== '')
                                <div class="permv2-row-desc" id="{{ $pillId }}-desc">{{ $row['warning'] }}</div>
                            @endif
                        </div>

                        <div class="permv2-pill"
                             id="{{ $pillId }}"
                             role="radiogroup"
                             aria-label="{{ $group['label'] }}: {{ $row['label'] }} permission level"
                             @if($row['warning'] !== '') aria-describedby="{{ $pillId }}-desc" @endif
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
