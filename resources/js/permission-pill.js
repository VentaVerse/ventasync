/* ============================================================
 * Permission-pill controller
 * ------------------------------------------------------------
 * Drives the segmented radiogroup UI on the user-group edit form.
 *
 * Data contract (matches existing backend):
 *   - Each row carries an inline <input type="hidden" name="permissions[]"
 *     value="{permissionId}" data-perm-key="{key}"> per tier (view/manage).
 *   - When a tier is INACTIVE the hidden input is detached (`disabled`),
 *     so it does NOT submit. When ACTIVE it's `disabled=false`.
 *   - The 2-segment pill carries ONLY a manage input.
 *   - The 3-segment pill carries one view + one manage input. Selecting
 *     "Manage" detaches view (manage implies view at the query layer:
 *     the backend doesn't need both rows in `user_group_permissions`).
 *
 * Keyboard model (per ARIA APG: radiogroup):
 *   - Tab moves between PILLS (roving tabindex).
 *   - Arrow Left/Right moves within a pill and immediately selects.
 *   - Home / End jump to first / last segment of the focused pill.
 *   - Space / Enter confirms (no-op when already selected).
 * ============================================================ */
(function () {
    'use strict';

    const picker = document.getElementById('perm-picker-v2');
    if (!picker) return;

    const counter = picker.querySelector('[data-permv2="counter"]');
    /* The counter the ear gets. The visible one reads "38 / 38", which is
       right for the eye and useless spoken, so it is aria-hidden and this
       carries the sentence. */
    const counterSay = picker.querySelector('[data-permv2="counter-say"]');
    const offCascade = picker.querySelector('.permv2-cascade[data-cascade="off"]');
    const counterTotal = parseInt(picker.dataset.total, 10) || 0;
    const pills = Array.from(picker.querySelectorAll('.permv2-pill'));
    const groups = Array.from(picker.querySelectorAll('.permv2-group'));
    const cascades = Array.from(picker.querySelectorAll('.permv2-cascade'));
    const searchInput = picker.querySelector('[data-permv2="search"]');
    const presetWrap = picker.querySelector('[data-permv2="preset"]');
    const presetTrigger = picker.querySelector('[data-permv2="preset-trigger"]');
    const presetMenu = picker.querySelector('[data-permv2="preset-menu"]');

    // ---------------------------------------------------------------- pill state
    function setPillState(pill, state) {
        // Derive the allowed states from the segment buttons actually in the
        // DOM, a 2-segment pill can be [off|manage] OR [off|view] depending
        // on whether the resource has a manage tier. Hardcoding [off,manage]
        // here would silently drop "view" cascades on read-only resources.
        const segs = pill.querySelectorAll('.permv2-pill-seg');
        const allowed = Array.from(segs).map((s) => s.dataset.value);
        if (!allowed.includes(state)) return;
        pill.dataset.state = state;

        segs.forEach((seg) => {
            const isActive = seg.dataset.value === state;
            seg.setAttribute('aria-checked', isActive ? 'true' : 'false');
            seg.tabIndex = isActive ? 0 : -1;
        });

        // Hidden inputs: enable the one matching state, disable the others.
        const inputs = pill.querySelectorAll('input[type="hidden"][data-tier]');
        inputs.forEach((inp) => {
            // `manage` selected → enable manage, disable view.
            // `view`   selected → enable view, disable manage.
            // `off`    selected → disable both.
            inp.disabled = !(state !== 'off' && inp.dataset.tier === state);
        });

        // Parent → children Off cascade. If THIS pill is the parent of its
        // group (i.e. its base matches the group's key) and the new state
        // is "off", flip every sibling pill in the same group to off too.
        // Matches the server-side cascade in UserGroupController so the
        // visible form state never diverges from what will be saved.
        // Recursion is bounded: cascaded children have a different base
        // than the group key, so they don't re-trigger this branch.
        const group = pill.closest('.permv2-group');
        if (group && state === 'off') {
            const groupKey = group.dataset.groupKey;
            const myBase = pill.dataset.base;
            if (myBase && myBase === groupKey) {
                group.querySelectorAll('.permv2-pill').forEach((sib) => {
                    if (sib !== pill && sib.dataset.state !== 'off') {
                        setPillState(sib, 'off');
                    }
                });
            }
        }

        // Child → parent View elevation. If THIS pill is a CHILD of its
        // group (its base differs from the group key) and the new state
        // grants any tier, ensure the parent pill is not Off, promote it
        // to "view" (or "manage" for container parents that don't expose
        // a view tier). Symmetric counterpart to the parent-off cascade
        // above: keeps the invariant that the parent's tier is always
        // >= max(children's tiers), so a child grant never lands as a
        // dead permission (parent Off hides the sidebar section,
        // rendering the child unreachable from nav).
        if (group && (state === 'view' || state === 'manage')) {
            const groupKey = group.dataset.groupKey;
            const myBase = pill.dataset.base;
            if (myBase && myBase !== groupKey) {
                const parentPill = group.querySelector('.permv2-pill[data-base="' + groupKey + '"]');
                if (parentPill && parentPill.dataset.state === 'off') {
                    const parentSupportsView = !!parentPill.querySelector('input[data-tier="view"]');
                    setPillState(parentPill, parentSupportsView ? 'view' : 'manage');
                }
            }
        }

        recomputeGroupStats(group);
        recomputeGlobal();
    }

    function recomputeGroupStats(group) {
        if (!group) return;
        const groupPills = group.querySelectorAll('.permv2-pill');
        let manageCount = 0;
        let viewCount = 0;
        groupPills.forEach((p) => {
            const s = p.dataset.state;
            if (s === 'manage') manageCount++;
            else if (s === 'view') viewCount++;
        });
        const manageStat = group.querySelector('[data-stat="manage"]');
        const viewStat = group.querySelector('[data-stat="view"]');
        if (manageStat) {
            manageStat.querySelector('[data-stat-value]').textContent = manageCount;
            manageStat.dataset.zero = manageCount === 0 ? '1' : '0';
        }
        if (viewStat) {
            viewStat.querySelector('[data-stat-value]').textContent = viewCount;
            viewStat.dataset.zero = viewCount === 0 ? '1' : '0';
        }
    }

    function recomputeGlobal() {
        let granted = 0;
        pills.forEach((p) => {
            if (p.dataset.state !== 'off') granted++;
        });
        if (counter) {
            counter.textContent = granted + ' / ' + counterTotal;
            counter.dataset.empty = granted === 0 ? '1' : '0';
            counter.dataset.full = granted === counterTotal ? '1' : '0';
        }

        if (counterSay) {
            counterSay.textContent = granted + ' of ' + counterTotal + ' areas granted';
        }

        /* The confirmation names what is actually about to go, because the
           number changes as the operator works and a fixed message would be
           wrong the moment they touched a pill. Read at click time by
           confirm-modal.js, so updating the attribute is enough. */
        if (offCascade) {
            offCascade.setAttribute(
                'data-confirm',
                granted === 0
                    ? 'Every area is already off for this group. Switch off anyway?'
                    : 'Switch off all ' + granted + ' granted area' + (granted === 1 ? '' : 's')
                        + ' for this group? Everyone in it loses that access when you save.'
            );
        }
    }

    // ---------------------------------------------------------------- click + kbd
    pills.forEach((pill) => {
        const segs = Array.from(pill.querySelectorAll('.permv2-pill-seg'));

        segs.forEach((seg, idx) => {
            seg.addEventListener('click', () => {
                setPillState(pill, seg.dataset.value);
                seg.focus();
            });

            seg.addEventListener('keydown', (ev) => {
                let nextIdx = null;
                switch (ev.key) {
                    case 'ArrowRight':
                    case 'ArrowDown':
                        nextIdx = (idx + 1) % segs.length;
                        break;
                    case 'ArrowLeft':
                    case 'ArrowUp':
                        nextIdx = (idx - 1 + segs.length) % segs.length;
                        break;
                    case 'Home':
                        nextIdx = 0;
                        break;
                    case 'End':
                        nextIdx = segs.length - 1;
                        break;
                    case ' ':
                    case 'Enter':
                        ev.preventDefault();
                        setPillState(pill, seg.dataset.value);
                        return;
                }
                if (nextIdx !== null) {
                    ev.preventDefault();
                    const target = segs[nextIdx];
                    setPillState(pill, target.dataset.value);
                    target.focus();
                }
            });
        });
    });

    // ---------------------------------------------------------------- group toggle
    groups.forEach((group) => {
        const head = group.querySelector('.permv2-group-head');
        if (!head) return;
        head.addEventListener('click', () => {
            const collapsed = group.dataset.collapsed === '1';
            group.dataset.collapsed = collapsed ? '0' : '1';
            head.setAttribute('aria-expanded', collapsed ? 'true' : 'false');
        });
    });

    // ---------------------------------------------------------------- cascade buttons
    function cascadeAll(targetState, scope) {
        const scopePills = scope
            ? Array.from(scope.querySelectorAll('.permv2-pill'))
            : pills;
        scopePills.forEach((pill) => {
            // A pill's actual supported segments depend on which tier
            // permissions exist for that resource base. Coerce to the
            // closest-meaningful tier:
            //   - "off"    → "off" (universal).
            //   - "view"   → "view" if supported, else stays at current
            //                tier's lowest available (i.e. no change if
            //                only manage exists, Operator/Viewer presets
            //                shouldn't accidentally over-grant).
            //   - "manage" → "manage" if supported, else fall back to
            //                "view" (view-only resources can't be "managed").
            const inputs = pill.querySelectorAll('input[type="hidden"][data-tier]');
            const supportsView = !!pill.querySelector('input[data-tier="view"]');
            const supportsManage = !!pill.querySelector('input[data-tier="manage"]');

            let coerced = targetState;
            if (targetState === 'manage' && !supportsManage) {
                coerced = supportsView ? 'view' : 'off';
            } else if (targetState === 'view' && !supportsView) {
                // Skip, view-only cascade shouldn't elevate a manage-only
                // resource to manage. Leave the pill alone.
                return;
            }
            // Only act on visible (not search-hidden) rows.
            const row = pill.closest('.permv2-row');
            if (row && row.dataset.hidden === '1') return;
            setPillState(pill, coerced);
        });
    }

    cascades.forEach((btn) => {
        btn.addEventListener('click', () => {
            cascadeAll(btn.dataset.cascade);
            // "Off all" must also detach orphan hidden inputs (container
            // permissions like manage_marketplace_api that have no pill).
            // For "View all" / "Manage all" we leave them attached, they're
            // implied-redundant given any child grant, but harmless.
            const orphans = picker.querySelectorAll('input[type="hidden"][data-orphan="1"]');
            orphans.forEach((inp) => {
                inp.disabled = (btn.dataset.cascade === 'off');
            });
        });
    });

    // ---------------------------------------------------------------- search
    function applySearch(query) {
        const q = query.trim().toLowerCase();
        let anyVisible = false;
        groups.forEach((group) => {
            const rows = group.querySelectorAll('.permv2-row');
            let groupHasMatch = false;
            const groupLabel = (group.querySelector('.permv2-group-title-text')?.textContent || '').toLowerCase();
            rows.forEach((row) => {
                if (q === '') {
                    row.dataset.hidden = '0';
                    groupHasMatch = true;
                    return;
                }
                const rowText = (row.dataset.search || row.textContent).toLowerCase();
                const matches = rowText.includes(q) || groupLabel.includes(q);
                row.dataset.hidden = matches ? '0' : '1';
                if (matches) groupHasMatch = true;
            });
            group.style.display = groupHasMatch ? '' : 'none';
            if (groupHasMatch) anyVisible = true;
            // Auto-expand groups during search for instant visibility.
            if (q !== '' && groupHasMatch) {
                group.dataset.collapsed = '0';
            }
        });
        picker.dataset.empty = (q !== '' && !anyVisible) ? '1' : '0';
    }

    if (searchInput) {
        let searchTimer = null;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(() => applySearch(searchInput.value), 80);
        });
        // Esc clears
        searchInput.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                searchInput.value = '';
                applySearch('');
            }
        });
    }

    // ---------------------------------------------------------------- preset menu
    if (presetTrigger && presetWrap) {
        presetTrigger.addEventListener('click', (ev) => {
            ev.stopPropagation();
            const open = presetWrap.dataset.open === '1';
            presetWrap.dataset.open = open ? '0' : '1';
            presetTrigger.setAttribute('aria-expanded', open ? 'false' : 'true');
        });
        document.addEventListener('click', (ev) => {
            if (!presetWrap.contains(ev.target)) {
                closePresets(false);
            }
        });

        /* Escape closes it and the focus goes back to the trigger.
           It declared aria-haspopup and role="menu" and implemented none of
           the contract - no arrow keys, no focus move, and Escape left it
           open with focus stranded inside a panel the operator had asked to
           dismiss. The roles are gone (see the partial, and the same call
           components/ui/menu.blade.php already documents); this is the part
           worth keeping, because every other dismissible thing in this
           application closes on Escape. */
        presetWrap.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && presetWrap.dataset.open === '1') {
                ev.stopPropagation();
                closePresets(true);
            }
        });
        presetMenu?.querySelectorAll('button[data-preset]').forEach((btn) => {
            btn.addEventListener('click', () => {
                applyPreset(btn.dataset.preset);
                closePresets(true);
            });
        });
    }

    function closePresets(returnFocus) {
        if (!presetWrap) return;
        presetWrap.dataset.open = '0';
        if (presetTrigger) {
            presetTrigger.setAttribute('aria-expanded', 'false');
            // Only when the operator dismissed it themselves. Stealing focus
            // on a click elsewhere would yank them back out of wherever they
            // just went.
            if (returnFocus) presetTrigger.focus();
        }
    }

    /**
     * Bulk presets, applied to ALL pills regardless of search state, so the
     * operator never accidentally creates an incoherent role by typing into
     * the search box first.
     *
     *   viewer        → every pill = view (or manage if view doesn't exist)
     *                   ...EXCEPT settings-related rows which stay off.
     *   operator      → every pill = manage  EXCEPT settings/user_groups/users
     *                   stay off (so the operator isn't accidentally an admin).
     *   administrator → every pill = manage.
     */
    function applyPreset(name) {
        /* "Settings-ish" is now just the area, because every key is
           area/name. That list of five bases it replaces was a hand-kept
           guess at the same question and had already missed api_clients and
           order_statuses - both of which an Operator preset happily granted. */
        const isSettingsKey = (base) => base.startsWith('settings/');

        pills.forEach((pill) => {
            const base = pill.dataset.base || '';
            const supportsView = !!pill.querySelector('input[data-tier="view"]');
            const supportsManage = !!pill.querySelector('input[data-tier="manage"]');

            // Pick the best tier each preset wants, falling back to whatever
            // the pill actually supports. A resource that only has view_X
            // (e.g. petty_cash_transactions) tops out at "view", Operator
            // and Administrator presets still grant it at that tier.
            const target = (preferred) => {
                if (preferred === 'manage') {
                    return supportsManage ? 'manage' : (supportsView ? 'view' : 'off');
                }
                if (preferred === 'view') {
                    return supportsView ? 'view' : (supportsManage ? 'manage' : 'off');
                }
                return 'off';
            };

            if (name === 'viewer') {
                setPillState(pill, isSettingsKey(base) ? 'off' : target('view'));
            } else if (name === 'operator') {
                setPillState(pill, isSettingsKey(base) ? 'off' : target('manage'));
            } else if (name === 'administrator') {
                setPillState(pill, target('manage'));
            }
        });
    }

    // ---------------------------------------------------------------- init
    // Init counters from server-rendered state.
    groups.forEach(recomputeGroupStats);
    recomputeGlobal();
})();
