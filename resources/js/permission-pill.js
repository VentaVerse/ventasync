(function () {
    'use strict';

    const picker = document.getElementById('perm-picker-v2');
    if (!picker) return;

    const counter = picker.querySelector('[data-permv2="counter"]');
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

    function setPillState(pill, state) {
        // Read allowed states from the DOM; hardcoding off/manage drops view cascades on read-only resources.
        const segs = pill.querySelectorAll('.permv2-pill-seg');
        const allowed = Array.from(segs).map((s) => s.dataset.value);
        if (!allowed.includes(state)) return;
        pill.dataset.state = state;

        segs.forEach((seg) => {
            const isActive = seg.dataset.value === state;
            seg.setAttribute('aria-checked', isActive ? 'true' : 'false');
            seg.tabIndex = isActive ? 0 : -1;
        });

        const inputs = pill.querySelectorAll('input[type="hidden"][data-tier]');
        inputs.forEach((inp) => {
            inp.disabled = !(state !== 'off' && inp.dataset.tier === state);
        });

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

        picker.querySelectorAll('button[data-preset][data-confirm-count]').forEach((btn) => {
            fill(btn, scopeLabel, summarise((pill) => presetTarget(pill, btn.dataset.preset), pills));
        });
    }

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

    function setCollapsed(group, collapsed) {
        const toggle = group.querySelector('.permv2-group-toggle');
        group.dataset.collapsed = collapsed ? '1' : '0';
        if (toggle) toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
    }

    groups.forEach((group) => {
        group.dataset.userCollapsed = group.dataset.collapsed;

        const head = group.querySelector('.permv2-group-toggle');
        if (!head) return;
        head.addEventListener('click', () => {
            setCollapsed(group, group.dataset.collapsed !== '1');
            group.dataset.userCollapsed = group.dataset.collapsed;
        });

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

    function onActivate(btn, fn) {
        const guarded = btn.hasAttribute('data-confirm') || btn.hasAttribute('data-confirm-count');
        btn.addEventListener(guarded ? 'confirm:accepted' : 'click', fn);
    }

    cascades.forEach((btn) => {
        onActivate(btn, () => cascadeAll(btn.dataset.cascade));
    });

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
          says: 'These are fields rather than screens. Look under Catalog for products '
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

            if (q !== '') {
                if (groupHasMatch) setCollapsed(group, false);
            } else {
                setCollapsed(group, group.dataset.userCollapsed === '1');
            }
        });
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
        searchInput.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape') {
                searchInput.value = '';
                applySearch('');
            }
        });
    }

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

        presetWrap.addEventListener('keydown', (ev) => {
            if (ev.key === 'Escape' && presetWrap.dataset.open === '1') {
                ev.stopPropagation();
                closePresets(true);
            }
        });
        presetMenu?.querySelectorAll('button[data-preset]').forEach((btn) => {
            btn.addEventListener('click', () => closePresets(false));
            onActivate(btn, () => applyPreset(btn.dataset.preset));
        });
    }

    function closePresets(returnFocus) {
        if (!presetWrap) return;
        presetWrap.dataset.open = '0';
        if (presetTrigger) {
            presetTrigger.setAttribute('aria-expanded', 'false');
            if (returnFocus) presetTrigger.focus();
        }
    }

    function applyPreset(name) {
        pills.forEach((pill) => {
            const target = presetTarget(pill, name);
            if (target !== null) setPillState(pill, target);
        });
    }

    function presetTarget(pill, name) {
        const isSettingsKey = (base) => base.startsWith('settings/');

        const base = pill.dataset.base || '';
        const supportsView = !!pill.querySelector('input[data-tier="view"]');
        const supportsManage = !!pill.querySelector('input[data-tier="manage"]');

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

    const toolbar = picker.querySelector('.permv2-toolbar');

    if (toolbar && 'ResizeObserver' in window) {
        const setToolbarHeight = () => {
            picker.style.setProperty('--permv2-toolbar-h', Math.ceil(toolbar.getBoundingClientRect().height) + 'px');
        };
        new ResizeObserver(setToolbarHeight).observe(toolbar);
        setToolbarHeight();
    }

    groups.forEach(recomputeGroupStats);
    recomputeGlobal();
})();
