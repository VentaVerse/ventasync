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

<style>
/* Permission Picker */
.perm-toolbar {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 16px; flex-wrap: wrap; gap: 8px;
}
.perm-toolbar-label {
    font-weight: 700; font-size: 14px; color: var(--text-primary);
    display: flex; align-items: center; gap: 8px;
}
.perm-toolbar-actions { display: flex; gap: 6px; }
.perm-toolbar-actions button {
    font-size: 12px; padding: 4px 10px; border-radius: var(--radius-sm);
    border: 1px solid var(--border); background: var(--surface); color: var(--text-secondary);
    cursor: pointer; transition: all var(--transition-fast);
}
.perm-toolbar-actions button:hover {
    background: var(--accent); color: #fff; border-color: var(--accent);
}

.perm-badge {
    display: inline-flex; align-items: center; justify-content: center;
    min-width: 22px; padding: 1px 7px; border-radius: 99px;
    font-size: 11px; font-weight: 600; line-height: 1.4;
}
.perm-badge-gray  { background: #f1f5f9; color: var(--text-muted); }
.perm-badge-blue  { background: rgba(59,130,246,.12); color: #2563eb; }
.perm-badge-green { background: rgba(34,197,94,.12); color: #16a34a; }

.perm-group {
    border: 1px solid var(--border); border-radius: var(--radius-md);
    margin-bottom: 12px; overflow: hidden;
}
.perm-group summary {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px; cursor: pointer; user-select: none;
    font-weight: 600; font-size: 13px; color: var(--text-primary);
    background: var(--surface-alt); list-style: none;
    transition: background var(--transition-fast);
}
.perm-group summary:hover { background: #f1f5f9; }
.perm-group summary::-webkit-details-marker { display: none; }
.perm-group summary::before {
    content: '';
    display: inline-block; width: 0; height: 0;
    border-left: 5px solid var(--text-muted);
    border-top: 4px solid transparent;
    border-bottom: 4px solid transparent;
    transition: transform var(--transition-fast);
    flex-shrink: 0;
}
.perm-group[open] > summary::before {
    transform: rotate(90deg);
}
.perm-group summary .perm-badge { margin-left: auto; }
.perm-group-body { padding: 0 16px 16px; }

/* Parent row */
.perm-parent-row {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px; margin-bottom: 12px;
    background: rgba(59,130,246,.06); border: 1px solid rgba(59,130,246,.15);
    border-radius: var(--radius-sm);
}
.perm-parent-row .perm-parent-info { flex: 1; }
.perm-parent-row .perm-parent-label {
    font-weight: 600; font-size: 13px; color: var(--text-primary);
}
.perm-parent-row .perm-parent-desc {
    font-size: 11.5px; color: var(--text-muted); margin-top: 1px;
}
.perm-parent-row .perm-parent-hint {
    font-size: 11px; color: var(--accent); font-weight: 500; white-space: nowrap;
}

/* Sub-group divider */
.perm-subgroup-label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .06em; color: var(--text-muted);
    padding: 4px 0 8px; margin-top: 4px;
    border-bottom: 1px solid var(--border-light);
    margin-bottom: 8px;
}

/* Children grid */
.perm-children-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
    gap: 8px;
}

/* Child card */
.perm-card {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 10px 12px; border: 1px solid var(--border);
    border-radius: var(--radius-sm); cursor: pointer;
    transition: all var(--transition-fast); background: var(--surface);
}
.perm-card:hover { border-color: var(--accent); background: rgba(59,130,246,.03); }
.perm-card.is-checked {
    background: rgba(59,130,246,.05); border-color: rgba(59,130,246,.25);
}
.perm-card-label { font-weight: 600; font-size: 13px; color: var(--text-primary); }
.perm-card-desc  { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }

/* Custom checkbox */
.perm-cb {
    appearance: none; -webkit-appearance: none;
    width: 18px; height: 18px; min-width: 18px;
    border: 2px solid #cbd5e1; border-radius: 4px;
    background: #fff; cursor: pointer; position: relative;
    transition: all var(--transition-fast); margin-top: 1px;
}
.perm-cb:checked {
    background: var(--accent); border-color: var(--accent);
}
.perm-cb:checked::after {
    content: '';
    position: absolute; left: 4px; top: 1px;
    width: 6px; height: 10px;
    border: solid #fff; border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
.perm-cb:indeterminate {
    background: var(--accent); border-color: var(--accent);
}
.perm-cb:indeterminate::after {
    content: '';
    position: absolute; left: 3px; top: 6px;
    width: 8px; height: 2px;
    background: #fff; border-radius: 1px;
}
</style>

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
            foreach ($groupChildren as $c) {
                if (in_array($c->id, $selected)) $checkedCount++;
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
                    <div class="perm-parent-hint">Select All in Group</div>
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
