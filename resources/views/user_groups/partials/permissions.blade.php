{{-- Shared permission picker for user group create/edit --}}
@php
    $selected = $selected ?? [];
    $totalPerms = 0;
    $totalSelected = 0;
    foreach ($permissionGroups as $pg) {
        $totalPerms++;
        if (in_array($pg['parent']->id, $selected)) $totalSelected++;
        if (!empty($pg['children'])) {
            foreach ($pg['children'] as $c) {
                $totalPerms++;
                if (in_array($c->id, $selected)) $totalSelected++;
            }
        }
        if (!empty($pg['subgroups'])) {
            foreach ($pg['subgroups'] as $sg) {
                foreach ($sg['children'] as $c) {
                    $totalPerms++;
                    if (in_array($c->id, $selected)) $totalSelected++;
                }
            }
        }
    }
@endphp

<div id="perm-picker">
    {{-- Global toolbar --}}
    <div class="perm-toolbar">
        <div class="perm-toolbar-label">
            Permissions
            <span id="perm-global-badge" class="perm-badge perm-badge-gray">{{ $totalSelected }} / {{ $totalPerms }}</span>
        </div>
        <div class="perm-toolbar-actions">
            <button type="button" id="perm-select-all">Select All</button>
            <button type="button" id="perm-deselect-all">Deselect All</button>
        </div>
    </div>

    {{-- Permission groups --}}
    @foreach($permissionGroups as $pg)
        @php
            $groupChildren = [];
            if (!empty($pg['children'])) {
                $groupChildren = $pg['children'];
            }
            if (!empty($pg['subgroups'])) {
                foreach ($pg['subgroups'] as $sg) {
                    $groupChildren = array_merge($groupChildren, $sg['children']);
                }
            }
            $childCount = count($groupChildren);
            $checkedCount = 0;
            $isStandalone = ($childCount === 0);
            if ($isStandalone) {
                // Standalone permission: badge reflects parent's own state
                $childCount = 1;
                $checkedCount = in_array($pg['parent']->id, $selected) ? 1 : 0;
            } else {
                foreach ($groupChildren as $c) {
                    if (in_array($c->id, $selected)) $checkedCount++;
                }
            }
        @endphp

        <details class="perm-group" open data-group="{{ $pg['key'] }}">
            <summary>
                {{ $pg['label'] }}
                <span class="perm-badge {{ $checkedCount === 0 ? 'perm-badge-gray' : ($checkedCount === $childCount ? 'perm-badge-green' : 'perm-badge-blue') }}"
                      data-badge-group="{{ $pg['key'] }}">{{ $checkedCount }} / {{ $childCount }}</span>
            </summary>

            <div class="perm-group-body">
                {{-- Parent row --}}
                <div class="perm-parent-row">
                    <input type="checkbox" class="perm-cb perm-parent-cb"
                           name="permissions[]" value="{{ $pg['parent']->id }}"
                           data-group="{{ $pg['key'] }}"
                           {{ in_array($pg['parent']->id, $selected) ? 'checked' : '' }}>
                    <div class="perm-parent-info">
                        <div class="perm-parent-label">{{ $pg['parent']->label }}</div>
                        @if($pg['parent']->description)
                            <div class="perm-parent-desc">{{ $pg['parent']->description }}</div>
                        @endif
                    </div>
                    @if(!$isStandalone)
                        <div class="perm-parent-hint">Select All in Group</div>
                    @endif
                </div>

                {{-- Children: flat list or sub-grouped --}}
                @if(!empty($pg['children']))
                    <div class="perm-children-grid">
                        @foreach($pg['children'] as $perm)
                            <label class="perm-card {{ in_array($perm->id, $selected) ? 'is-checked' : '' }}">
                                <input type="checkbox" class="perm-cb perm-child-cb"
                                       name="permissions[]" value="{{ $perm->id }}"
                                       data-group="{{ $pg['key'] }}"
                                       {{ in_array($perm->id, $selected) ? 'checked' : '' }}>
                                <div>
                                    <div class="perm-card-label">{{ $perm->label }}</div>
                                    @if($perm->description)
                                        <div class="perm-card-desc">{{ $perm->description }}</div>
                                    @endif
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif

                @if(!empty($pg['subgroups']))
                    @foreach($pg['subgroups'] as $sg)
                        <div class="perm-subgroup-label">{{ $sg['label'] }}</div>
                        <div class="perm-children-grid" style="margin-bottom:12px;">
                            @foreach($sg['children'] as $perm)
                                <label class="perm-card {{ in_array($perm->id, $selected) ? 'is-checked' : '' }}">
                                    <input type="checkbox" class="perm-cb perm-child-cb"
                                           name="permissions[]" value="{{ $perm->id }}"
                                           data-group="{{ $pg['key'] }}"
                                           {{ in_array($perm->id, $selected) ? 'checked' : '' }}>
                                    <div>
                                        <div class="perm-card-label">{{ $perm->label }}</div>
                                        @if($perm->description)
                                            <div class="perm-card-desc">{{ $perm->description }}</div>
                                        @endif
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                @endif
            </div>
        </details>
    @endforeach
</div>

@push('scripts')
<script>
(function() {
    var picker = document.getElementById('perm-picker');
    if (!picker) return;

    var allCheckboxes  = picker.querySelectorAll('.perm-cb');
    var parentCbs      = picker.querySelectorAll('.perm-parent-cb');
    var childCbs       = picker.querySelectorAll('.perm-child-cb');
    var globalBadge    = document.getElementById('perm-global-badge');
    var selectAllBtn   = document.getElementById('perm-select-all');
    var deselectAllBtn = document.getElementById('perm-deselect-all');

    function updateGroupBadge(groupKey) {
        var children = picker.querySelectorAll('.perm-child-cb[data-group="' + groupKey + '"]');
        var total = children.length;
        var checked = 0;
        children.forEach(function(c) { if (c.checked) checked++; });

        // Standalone permission (no children) — badge reflects parent state
        if (total === 0) {
            var parent = picker.querySelector('.perm-parent-cb[data-group="' + groupKey + '"]');
            total = 1;
            checked = (parent && parent.checked) ? 1 : 0;
        }

        var badge = picker.querySelector('[data-badge-group="' + groupKey + '"]');
        if (badge) {
            badge.textContent = checked + ' / ' + total;
            badge.className = 'perm-badge ' +
                (checked === 0 ? 'perm-badge-gray' : (checked === total ? 'perm-badge-green' : 'perm-badge-blue'));
        }
    }

    function updateGlobalBadge() {
        var total = allCheckboxes.length;
        var checked = 0;
        allCheckboxes.forEach(function(c) { if (c.checked) checked++; });
        globalBadge.textContent = checked + ' / ' + total;
        globalBadge.className = 'perm-badge ' +
            (checked === 0 ? 'perm-badge-gray' : (checked === total ? 'perm-badge-green' : 'perm-badge-blue'));
    }

    function updateParentState(groupKey) {
        var parent = picker.querySelector('.perm-parent-cb[data-group="' + groupKey + '"]');
        if (!parent) return;
        var children = picker.querySelectorAll('.perm-child-cb[data-group="' + groupKey + '"]');
        var total = children.length;

        // Standalone permission (no children) — parent acts as its own toggle,
        // so preserve its checked state as-is instead of deriving it from children.
        if (total === 0) return;

        var checked = 0;
        children.forEach(function(c) { if (c.checked) checked++; });

        if (checked === 0) {
            parent.checked = false;
            parent.indeterminate = false;
        } else if (checked === total) {
            parent.checked = true;
            parent.indeterminate = false;
        } else {
            parent.checked = false;
            parent.indeterminate = true;
        }
    }

    function updateCardHighlight(cb) {
        var card = cb.closest('.perm-card');
        if (card) {
            card.classList.toggle('is-checked', cb.checked);
        }
    }

    // Parent toggle all children
    parentCbs.forEach(function(parent) {
        parent.addEventListener('change', function() {
            var groupKey = parent.dataset.group;
            var children = picker.querySelectorAll('.perm-child-cb[data-group="' + groupKey + '"]');
            children.forEach(function(child) {
                child.checked = parent.checked;
                updateCardHighlight(child);
            });
            parent.indeterminate = false;
            updateGroupBadge(groupKey);
            updateGlobalBadge();
        });
    });

    // Child updates parent
    childCbs.forEach(function(child) {
        child.addEventListener('change', function() {
            var groupKey = child.dataset.group;
            updateCardHighlight(child);
            updateParentState(groupKey);
            updateGroupBadge(groupKey);
            updateGlobalBadge();
        });
    });

    // Global Select All
    selectAllBtn.addEventListener('click', function() {
        allCheckboxes.forEach(function(cb) {
            cb.checked = true;
            cb.indeterminate = false;
            updateCardHighlight(cb);
        });
        picker.querySelectorAll('[data-badge-group]').forEach(function(badge) {
            updateGroupBadge(badge.dataset.badgeGroup);
        });
        // Update all group badges
        parentCbs.forEach(function(p) { updateGroupBadge(p.dataset.group); });
        updateGlobalBadge();
    });

    // Global Deselect All
    deselectAllBtn.addEventListener('click', function() {
        allCheckboxes.forEach(function(cb) {
            cb.checked = false;
            cb.indeterminate = false;
            updateCardHighlight(cb);
        });
        parentCbs.forEach(function(p) { updateGroupBadge(p.dataset.group); });
        updateGlobalBadge();
    });

    // Init: set parent indeterminate states on load
    parentCbs.forEach(function(p) { updateParentState(p.dataset.group); });
})();
</script>
@endpush
