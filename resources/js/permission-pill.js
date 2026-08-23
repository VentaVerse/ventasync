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

        /* THE PARENT/CHILD CASCADE IS GONE, WITH THE TREE IT SERVED.
           It compared pill.dataset.base ("catalog/product") against
           group.dataset.groupKey ("catalog"), which can never be equal now
           that every key is area/name - so both branches were unreachable.
           An area is a folder of controllers; no controller is the parent of
           another. edit.blade.php promised this behaviour in prose long
           after the code stopped doing it; that sentence is gone too. */
        const group = pill.closest('.permv2-group');

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

        const zeroHint = picker.querySelector('[data-permv2="zerohint"]');

        if (zeroHint) {
            zeroHint.hidden = granted !== 0;
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

        refreshConfirms();
    }

    /* ----------------------------------------------------- confirmations
     *
     * WIDENING IS GUARDED AS CAREFULLY AS REVOKING.
     *
     * "Off all" was confirmed and "Manage all" was not, which had it exactly
     * backwards. Switching everything off is loud: someone rings within the
     * hour and it is one click back. Granting all sixty-four areas is silent
     * forever, and one of the areas it grants is the power to grant.
     *
     * Each message is rebuilt whenever the state or the filter changes, so
     * it names THIS click:
     *   :n  what the action covers - the filter narrows cascades, never presets
     *   :c  how many areas would actually change
     *   :e  the consequential rows it hands over, and only when it hands one
     */
    const warnedNames = picker.dataset.warnedNames || '';

    function plural(n, word) {
        return n + ' ' + word + (n === 1 ? '' : 's');
    }

    function summarise(targetFor, scope) {
        let changes = 0;
        let grantsWarned = false;

        scope.forEach((pill) => {
            const target = targetFor(pill);
            if (target === null || target === pill.dataset.state) return;
            changes++;
            const row = pill.closest('.permv2-row');
            if (target !== 'off' && row && row.dataset.warn === '1') {
                grantsWarned = true;
            }
        });

        return { changes, grantsWarned };
    }

    function fill(btn, scopeLabel, summary) {
        const template = btn.getAttribute('data-confirm-count');
        if (!template) return;

        const warnedClause = summary.grantsWarned && warnedNames
            ? ' It hands over ' + warnedNames + ' - rows that reach past their own screen.'
            : '';

        btn.setAttribute('data-confirm', template
            .replace(':n', scopeLabel)
            .replace(':c', summary.changes === 0
                ? 'Nothing'
                : plural(summary.changes, 'area'))
            .replace(':e', warnedClause));
    }

    function refreshConfirms() {
        const scope = cascadeScope();
        const filtered = scope.length !== pills.length;
        const scopeLabel = filtered
            ? 'the ' + plural(scope.length, 'area') + ' matching your filter'
            : 'all ' + plural(pills.length, 'area');

        picker.querySelectorAll('.permv2-cascade[data-confirm-count]').forEach((btn) => {
            fill(btn, scopeLabel, summarise((pill) => cascadeTarget(pill, btn.dataset.cascade), scope));
        });

        // Presets ignore the filter on purpose, so they are always scoped to
        // every pill and their wording says so rather than quoting a total
        // the operator cannot see.
        picker.querySelectorAll('button[data-preset][data-confirm-count]').forEach((btn) => {
            fill(btn, scopeLabel, summarise((pill) => presetTarget(pill, btn.dataset.preset), pills));
        });
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
    /* THE ONLY PLACE COLLAPSE IS WRITTEN.
       It used to be written in two: this handler set both halves, and
       applySearch set dataset.collapsed alone. After any search, ten of
       fifteen heads reported "collapsed" over nine visible rows that were
       still in the tab order. One function, both halves, always. */
    function setCollapsed(group, collapsed) {
        const toggle = group.querySelector('.permv2-group-toggle');
        group.dataset.collapsed = collapsed ? '1' : '0';
        if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    groups.forEach((group) => {
        /* The restore baseline is wherever the group STARTED, not only
           where the operator clicked it. Without this seed, one search
           popped the machine areas open permanently - their collapse came
           from the server, so the click handler had never recorded it. */
        group.dataset.userCollapsed = group.dataset.collapsed;

        const head = group.querySelector('.permv2-group-toggle');
        if (!head) return;
        head.addEventListener('click', () => {
            setCollapsed(group, group.dataset.collapsed !== '1');
            // The operator's own choice, so it survives the next search.
            group.dataset.userCollapsed = group.dataset.collapsed;
        });

        /* The area's own set-all. Scoped to this group's VISIBLE rows -
           same filter-respecting rule the global cascades keep - and routed
           through onActivate so a guarded button waits for its dialog. */
        group.querySelectorAll('[data-groupset]').forEach((btn) => {
            onActivate(btn, () => {
                group.querySelectorAll('.permv2-pill').forEach((pill) => {
                    const row = pill.closest('.permv2-row');
                    if (row && row.dataset.hidden === '1') return;
                    const coerced = cascadeTarget(pill, btn.dataset.groupset);
                    if (coerced !== null) setPillState(pill, coerced);
                });
            });
        });
    });

    // ---------------------------------------------------------------- cascade buttons
    /* WHAT ONE CASCADE WOULD DO TO ONE PILL, in one place.
       The confirmation quotes a number; the click applies a change. When
       two pieces of code work that out separately they drift, and the
       drift shows up as a dialog that promises the wrong thing. */
    function cascadeTarget(pill, targetState) {
        const supportsView = !!pill.querySelector('input[data-tier="view"]');
        const supportsManage = !!pill.querySelector('input[data-tier="manage"]');

        if (targetState === 'manage' && !supportsManage) {
            return supportsView ? 'view' : 'off';
        }
        // A view-only cascade must not elevate a manage-only row to manage.
        if (targetState === 'view' && !supportsView) return null;
        return targetState;
    }

    /* The pills one cascade may touch: cascades respect the filter, so a
       hidden row is out of scope. Presets deliberately do not - see
       applyPreset - and the confirmations say which is which. */
    function cascadeScope() {
        return pills.filter((pill) => {
            const row = pill.closest('.permv2-row');
            return !(row && row.dataset.hidden === '1');
        });
    }

    function cascadeAll(targetState) {
        cascadeScope().forEach((pill) => {
            const coerced = cascadeTarget(pill, targetState);
            if (coerced === null) return;
            setPillState(pill, coerced);
        });
    }

    /* Every widening action is guarded now, so no handler may fire on the
       raw click: confirm-modal.js preventDefaults the default action but
       cannot stop this listener, so acting here would run the cascade while
       the dialog was still asking, and Cancel would undo nothing. Guarded
       buttons wait for confirm:accepted instead. */
    function onActivate(btn, fn) {
        const guarded = btn.hasAttribute('data-confirm') || btn.hasAttribute('data-confirm-count');
        btn.addEventListener(guarded ? 'confirm:accepted' : 'click', fn);
    }

    cascades.forEach((btn) => {
        // The orphan-input sweep went with the container permissions it
        // existed for: every key is now area/name and every one has a pill.
        onActivate(btn, () => cascadeAll(btn.dataset.cascade));
    });

    /* Screens that gate themselves rather than at the door.
       They are absent from this page on purpose, so a search for one should
       say why instead of returning the same dead end as a typo. Keyed on the
       words an operator would actually type, and each answer names where the
       permission really lives. */
    const SELF_GATED = [
        { words: ['fulfilment', 'fulfillment', 'pack', 'pick', 'ship', 'awb', 'waybill'],
          says: 'The fulfilment hub is not one permission. It shows each channel\u2019s queue '
              + 'to whoever holds that channel, so grant Shopee, Lazada or TikTok Shop instead.' },
        { words: ['channel', 'channels', 'hub', 'integration', 'integrations', 'marketplace'],
          says: 'The channels hub is not one permission. It shows only the cards a person\u2019s '
              + 'own permissions already allow, so grant the channel itself instead.' },
        { words: ['automation', 'automations'],
          says: 'Automations are gated per row, by the integration each one touches. '
              + 'Grant the channel and its automations come with it.' },
        { words: ['dashboard', 'home', 'search'],
          says: 'Everyone signed in gets this one. It has no permission to grant.' },
        { words: ['stock', 'inventory', 'price', 'quantity'],
          says: 'These are fields rather than screens. Look under Catalogue for products '
              + 'and Warehousing for stock on hand.' },
    ];

    function explainQuery(q) {
        const why = picker.querySelector('[data-permv2="why"]');
        if (!why) return false;
        const hit = q.length >= 3
            && SELF_GATED.find((e) => e.words.some((w) => w.startsWith(q) || q.startsWith(w)));
        why.textContent = hit ? hit.says : '';
        why.hidden = !hit;
        return !!hit;
    }

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

            /* Searching borrows the collapse state; it does not take it.
               Force-expanding a match is right - a hit inside a shut group
               is a hit nobody sees - but it used to be permanent, so one
               keystroke undid however long the operator had spent shutting
               fourteen areas to make sixty-four rows tractable. */
            if (q !== '') {
                if (groupHasMatch) setCollapsed(group, false);
            } else {
                setCollapsed(group, group.dataset.userCollapsed === '1');
            }
        });
        /* When the explanatory note is answering ("the fulfilment hub is
           not one permission..."), a second line saying "Nothing matches"
           argues with it. The note alone is the answer. */
        const explained = explainQuery(q);
        picker.dataset.empty = (q !== '' && !anyVisible && !explained) ? '1' : '0';
        refreshConfirms();
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
            // Dismiss the menu on the click so the dialog is not asking from
            // behind it, but do not return focus - confirm-modal is taking
            // it, and puts it back on this button when it closes.
            btn.addEventListener('click', () => closePresets(false));
            onActivate(btn, () => applyPreset(btn.dataset.preset));
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
        pills.forEach((pill) => {
            const target = presetTarget(pill, name);
            if (target !== null) setPillState(pill, target);
        });
    }

    function presetTarget(pill, name) {
        /* "Settings-ish" is now just the area, because every key is
           area/name. That list of five bases it replaces was a hand-kept
           guess at the same question and had already missed api_clients and
           order_statuses - both of which an Operator preset happily granted. */
        const isSettingsKey = (base) => base.startsWith('settings/');

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

        if (name === 'viewer') return isSettingsKey(base) ? 'off' : target('view');
        if (name === 'operator') return isSettingsKey(base) ? 'off' : target('manage');
        if (name === 'administrator') return target('manage');
        return null;
    }

    /* THE STICKY OFFSET IS MEASURED, NOT ASSUMED. The area heads pin just
       below the toolbar, and the toolbar's height changes when it wraps at
       tablet widths - a hardcoded offset left every head 23px underneath
       it there. One observer keeps the CSS variable equal to reality. */
    const toolbar = picker.querySelector('.permv2-toolbar');

    if (toolbar && 'ResizeObserver' in window) {
        const setToolbarHeight = () => {
            picker.style.setProperty('--permv2-toolbar-h', Math.ceil(toolbar.getBoundingClientRect().height) + 'px');
        };
        new ResizeObserver(setToolbarHeight).observe(toolbar);
        setToolbarHeight();
    }

    // ---------------------------------------------------------------- init
    // Init counters from server-rendered state.
    groups.forEach(recomputeGroupStats);
    recomputeGlobal();
})();
