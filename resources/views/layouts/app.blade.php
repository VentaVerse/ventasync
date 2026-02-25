<!DOCTYPE html>
<html>
<head>
    <title>Shop A Inventory</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           DESIGN SYSTEM — Shop A Admin
           Modern SaaS admin panel (mid-2020s)
           ============================================================ */

        /* --- CSS Custom Properties --- */
        :root {
            --sidebar-w: 260px;
            --bg: #0b1120;
            --bg2: #101827;
            --bg3: #1e293b;
            --surface: #ffffff;
            --surface-hover: #f8fafc;
            --surface-alt: #f8fafc;
            --border: #e2e8f0;
            --border-light: #f1f5f9;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --text-muted: #94a3b8;
            --accent: #3b82f6;
            --accent-hover: #2563eb;
            --accent-light: rgba(59,130,246,.08);
            --danger: #ef4444;
            --danger-hover: #dc2626;
            --success: #22c55e;
            --warning: #f59e0b;
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
            --shadow-sm: 0 1px 3px rgba(0,0,0,.06);
            --shadow-md: 0 4px 16px rgba(0,0,0,.08);
            --shadow-lg: 0 12px 40px rgba(0,0,0,.12);
            --transition-fast: 150ms ease;
            --transition-normal: 250ms ease;
            --transition-slow: 400ms cubic-bezier(.4,0,.2,1);
        }

        /* --- Reset & Base --- */
        *, *::before, *::after { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: #f1f5f9;
            color: var(--text-primary);
            font-size: 14px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* --- Animations --- */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(6px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes slideInLeft {
            from { transform: translateX(-100%); }
            to { transform: translateX(0); }
        }
        @keyframes slideInDown {
            from { opacity: 0; transform: translateY(-16px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* --- Scrollbar --- */
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 99px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

        /* --- Layout --- */
        .layout { display: flex; min-height: 100vh; min-height: 100dvh; }

        /* ============================================================
           SIDEBAR
           ============================================================ */
        .sidebar {
            width: var(--sidebar-w);
            background: var(--bg2);
            color: #e2e8f0;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            height: 100vh;
            height: 100dvh;
            display: flex;
            flex-direction: column;
            z-index: 1200;
            transition: transform var(--transition-slow);
            will-change: transform;
        }
        .sidebar.collapsed { transform: translateX(calc(-1 * var(--sidebar-w))); }

        .sidebar-inner {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
            padding: 0 12px 20px;
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
        .sidebar-inner::-webkit-scrollbar { display: none; }

        /* Brand */
        .brand {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 20px 16px 16px;
            border-bottom: 1px solid rgba(148,163,184,.12);
            margin-bottom: 8px;
        }
        .brand-stack { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
        .brand-logo { height: 56px; width: auto; object-fit: contain; display: block; }
        .brand-company { font-weight: 700; font-size: 15px; letter-spacing: .3px; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #f1f5f9; }
        .brand-user { font-size: 12px; color: #94a3b8; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Toggle / Hamburger */
        .toggle-btn {
            border: 1px solid rgba(148,163,184,.2);
            background: transparent;
            color: #e2e8f0;
            border-radius: var(--radius-sm);
            padding: 6px 8px;
            cursor: pointer;
            display: flex;
            align-items: center;
            transition: background var(--transition-fast);
        }
        .toggle-btn:hover { background: rgba(148,163,184,.1); }
        .toggle-hamburger { display: inline-flex; align-items: center; justify-content: center; width: 20px; height: 20px; }
        .toggle-hamburger svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Sidebar FAB (mobile) */
        .sidebar-fab {
            position: fixed;
            top: 14px; left: 14px;
            z-index: 1300;
            border: 1px solid rgba(148,163,184,.2);
            background: var(--bg2);
            color: #e2e8f0;
            border-radius: var(--radius-md);
            padding: 10px 12px;
            cursor: pointer;
            display: none;
            align-items: center;
            gap: 8px;
            box-shadow: 0 4px 20px rgba(0,0,0,.3);
            transition: background var(--transition-fast);
        }
        .sidebar-fab:hover { background: rgba(148,163,184,.1); }
        .sidebar-fab.show { display: flex; }
        .sidebar-fab svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

        /* Sidebar backdrop (mobile) */
        .sidebar-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 1199;
            opacity: 0;
            transition: opacity var(--transition-normal);
        }
        .sidebar-backdrop.visible { opacity: 1; }

        /* --- Nav groups (animated details/summary) --- */
        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding-top: 8px;
        }

        /* Top-level link (Dashboard) */
        .sidebar-nav > a {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #94a3b8;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            font-size: 13.5px;
            font-weight: 500;
            transition: background var(--transition-fast), color var(--transition-fast);
        }
        .sidebar-nav > a:hover { background: rgba(148,163,184,.08); color: #e2e8f0; }
        .sidebar-nav > a.active {
            background: rgba(59,130,246,.12);
            color: #fff;
            position: relative;
        }
        .sidebar-nav > a.active::before {
            content: '';
            position: absolute;
            left: 0; top: 6px; bottom: 6px;
            width: 3px;
            background: var(--accent);
            border-radius: 0 3px 3px 0;
        }
        .sidebar-nav > a .nav-icon { display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; flex-shrink: 0; }
        .sidebar-nav > a .nav-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }

        /* Nav group (details/summary) */
        .nav-group { margin: 2px 0; }
        .nav-group details { /* no default styling */ }
        .nav-group summary {
            list-style: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            color: #94a3b8;
            font-size: 13.5px;
            font-weight: 500;
            transition: background var(--transition-fast), color var(--transition-fast);
            user-select: none;
        }
        .nav-group summary::-webkit-details-marker { display: none; }
        .nav-group summary::marker { display: none; content: ''; }
        .nav-group summary:hover { background: rgba(148,163,184,.08); color: #e2e8f0; }
        .nav-group summary.active { color: #e2e8f0; }
        .nav-group details[open] > summary { background: rgba(148,163,184,.06); color: #e2e8f0; }

        .nav-group summary .nav-icon { display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; flex-shrink: 0; }
        .nav-group summary .nav-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }

        .nav-group summary .label { flex: 1; }

        /* Chevron rotation */
        .nav-group summary .chevron {
            display: flex;
            align-items: center;
            transition: transform var(--transition-normal);
        }
        .nav-group summary .chevron svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
        .nav-group details[open] > summary .chevron { transform: rotate(90deg); }

        /* Animated sub-menu — height driven by JS */
        .nav-sub-wrap {
            overflow: hidden;
            background: rgba(0,0,0,.12);
            border-radius: 0 0 var(--radius-sm) var(--radius-sm);
            transition: height 500ms cubic-bezier(.25,.1,.25,1);
        }
        .nav-sub {
            display: flex;
            flex-direction: column;
            gap: 1px;
            padding-left: 20px;
            padding-top: 2px;
            padding-bottom: 4px;
        }
        .nav-sub a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
            font-weight: 400;
            transition: background var(--transition-fast), color var(--transition-fast);
            position: relative;
        }
        .nav-sub a:hover { background: rgba(148,163,184,.08); color: #e2e8f0; }
        .nav-sub a.active {
            background: rgba(59,130,246,.12);
            color: #fff;
        }
        .nav-sub a.active::before {
            content: '';
            position: absolute;
            left: 0; top: 6px; bottom: 6px;
            width: 3px;
            background: var(--accent);
            border-radius: 0 3px 3px 0;
        }
        .nav-sub a .nav-icon { display: flex; align-items: center; justify-content: center; width: 18px; height: 18px; flex-shrink: 0; }
        .nav-sub a .nav-icon svg { width: 16px; height: 16px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }

        /* Section divider inside nav-sub */
        .nav-sub-divider {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 12px 4px;
            font-size: 10.5px;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #64748b;
            user-select: none;
        }
        .nav-sub-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: rgba(148,163,184,.15);
        }

        /* Sidebar footer */
        .sidebar-footer {
            padding: 12px 12px 16px;
            border-top: 1px solid rgba(148,163,184,.12);
        }
        .sidebar-footer form { margin: 0; }
        .sidebar-footer .logout-btn {
            width: 100%;
            background: none;
            border: none;
            cursor: pointer;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #94a3b8;
            padding: 10px 12px;
            border-radius: var(--radius-sm);
            white-space: nowrap;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 500;
            transition: background var(--transition-fast), color var(--transition-fast);
        }
        .sidebar-footer .logout-btn:hover { background: rgba(148,163,184,.08); color: #e2e8f0; }
        .sidebar-footer .logout-btn .nav-icon { display: flex; align-items: center; justify-content: center; width: 20px; height: 20px; }
        .sidebar-footer .logout-btn .nav-icon svg { width: 18px; height: 18px; stroke: currentColor; fill: none; stroke-width: 1.75; stroke-linecap: round; stroke-linejoin: round; }

        /* ============================================================
           CONTENT AREA
           ============================================================ */
        .content {
            margin-left: var(--sidebar-w);
            padding: 0 32px 32px;
            width: 100%;
            min-height: 100vh;
            min-height: 100dvh;
            transition: margin-left var(--transition-slow);
            will-change: margin-left;
            animation: fadeIn .3s ease;
        }
        .content.collapsed { margin-left: 0; }

        /* Topbar / Breadcrumb bar */
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 18px 0 14px;
            margin-bottom: 4px;
        }
        .topbar-title {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        /* ============================================================
           ALERTS / TOAST
           ============================================================ */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-md);
            font-size: 13.5px;
            font-weight: 500;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            animation: slideInDown .3s ease;
            position: relative;
        }
        .alert .alert-close {
            position: absolute;
            top: 10px; right: 12px;
            background: none;
            border: none;
            cursor: pointer;
            color: inherit;
            opacity: .6;
            font-size: 16px;
            line-height: 1;
            padding: 0;
            transition: opacity var(--transition-fast);
        }
        .alert .alert-close:hover { opacity: 1; }
        .alert.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .alert.danger {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .alert.warning {
            background: #fffbeb;
            color: #92400e;
            border: 1px solid #fde68a;
        }

        /* Toast container (fixed bottom-right) */
        .toast-container {
            position: fixed;
            bottom: 20px; right: 20px;
            z-index: 9999;
            display: flex;
            flex-direction: column-reverse;
            gap: 8px;
            max-width: 400px;
            pointer-events: none;
        }
        .toast-container .alert {
            margin-bottom: 0;
            pointer-events: auto;
            box-shadow: 0 8px 30px rgba(0,0,0,.12);
            animation: toastIn .4s cubic-bezier(.21,1.02,.73,1);
        }
        .toast-container .alert.toast-out {
            animation: toastOut .35s ease forwards;
        }
        @keyframes toastIn {
            from { opacity: 0; transform: translateY(16px) scale(.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        @keyframes toastOut {
            to { opacity: 0; transform: translateY(16px) scale(.95); }
        }

        /* ============================================================
           CARDS
           ============================================================ */
        .card {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--border-light);
        }

        /* ============================================================
           PAGE HEADER
           ============================================================ */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px;
        }
        .page-header h2 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--text-primary);
            letter-spacing: -.01em;
        }
        .page-header-actions {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }

        /* ============================================================
           BUTTONS
           ============================================================ */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            height: 40px;
            padding: 0 18px;
            background: var(--accent);
            color: #fff;
            text-decoration: none;
            border-radius: var(--radius-md);
            border: none;
            cursor: pointer;
            font-family: inherit;
            font-size: 13.5px;
            font-weight: 600;
            white-space: nowrap;
            transition: background var(--transition-fast), box-shadow var(--transition-fast), transform var(--transition-fast);
            box-shadow: 0 1px 2px rgba(0,0,0,.06);
        }
        .btn:hover {
            background: var(--accent-hover);
            box-shadow: 0 4px 12px rgba(59,130,246,.25);
            transform: translateY(-1px);
        }
        .btn:active { transform: translateY(0) scale(.98); }
        .btn:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

        .btn.secondary {
            background: #1e293b;
            color: #fff;
            border: 1px solid rgba(255,255,255,.08);
        }
        .btn.secondary:hover { background: #334155; box-shadow: 0 4px 12px rgba(0,0,0,.15); }

        .btn.danger {
            background: var(--danger);
            color: #fff;
        }
        .btn.danger:hover { background: var(--danger-hover); box-shadow: 0 4px 12px rgba(239,68,68,.25); }

        .btn.small {
            height: 34px;
            padding: 0 12px;
            font-size: 12.5px;
            border-radius: var(--radius-sm);
        }
        .btn-sm { height: 32px; padding: 0 12px; font-size: 12px; border-radius: var(--radius-sm); }
        .btn-lg { height: 48px; padding: 0 24px; font-size: 15px; border-radius: var(--radius-md); }

        /* Loading state */
        .btn.is-loading {
            pointer-events: none;
            opacity: .65;
            position: relative;
        }
        .btn.is-loading::after {
            content: '';
            width: 14px; height: 14px;
            border: 2px solid rgba(255,255,255,.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin .55s linear infinite;
            margin-left: 6px;
            flex-shrink: 0;
        }

        /* ============================================================
           TABLES
           ============================================================ */
        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border-radius: var(--radius-md);
            border: 1px solid var(--border);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            border-right: 1px solid var(--border);
            text-align: left;
            vertical-align: middle;
        }
        th:last-child, td:last-child { border-right: none; }
        th {
            color: var(--text-secondary);
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: .05em;
            background: var(--surface-alt);
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
            border-bottom: 2px solid var(--border);
        }
        tbody tr { transition: background var(--transition-fast); }
        tbody tr:hover { background: rgba(59,130,246,.045); }
        tbody tr:last-child td { border-bottom: none; }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .text-nowrap { white-space: nowrap; }
        .text-muted { color: var(--text-muted); }
        .text-secondary { color: var(--text-secondary); }
        .font-semibold { font-weight: 600; }
        .font-bold { font-weight: 700; }

        /* ============================================================
           TOOLBAR
           ============================================================ */
        .toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin: 0 0 16px;
        }
        .toolbar form { display: flex; gap: 8px; align-items: center; margin: 0; flex-wrap: wrap; }

        /* ============================================================
           FORMS
           ============================================================ */
        .input {
            height: 40px;
            padding: 0 12px;
            border: 1px solid #cbd5e1;
            border-radius: var(--radius-md);
            background: var(--surface);
            color: var(--text-primary);
            font-family: inherit;
            font-size: 14px;
            width: 100%;
            transition: border-color var(--transition-fast), box-shadow var(--transition-fast);
        }
        .input:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(59,130,246,.12);
            position: relative;
            z-index: 20;
        }
        .input::placeholder { color: var(--text-muted); }
        select.input { cursor: pointer; appearance: auto; }
        textarea.input { min-height: 110px; height: auto; padding: 10px 12px; resize: vertical; }

        .form-grid { display: grid; gap: 16px; grid-template-columns: 1fr 1fr; }
        .form-grid .full { grid-column: 1 / -1; }
        label { font-size: 13px; font-weight: 500; color: var(--text-secondary); display: block; margin-bottom: 6px; }
        label.required::after { content: ' *'; color: var(--danger); font-weight: 700; }
        .hint { font-size: 12px; color: var(--text-muted); margin-top: 6px; }
        .field-error { font-size: 12px; color: var(--danger); margin-top: 4px; }
        .input-error { border-color: var(--danger) !important; box-shadow: 0 0 0 2px rgba(220,38,38,0.15); }

        /* Section heading (inside forms) */
        .section-title {
            margin: 24px 0 12px;
            font-size: 15px;
            font-weight: 600;
            color: var(--text-primary);
            padding-bottom: 8px;
            border-bottom: 2px solid var(--border);
        }
        .section-title:first-child { margin-top: 0; }

        /* Cost Breakdown */
        .cost-breakdown {
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 16px 20px;
        }
        .cost-breakdown-label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-bottom: 12px;
        }
        .cost-breakdown-fields {
            display: grid;
            grid-template-columns: 1fr auto 1fr auto 1fr auto 1fr;
            gap: 0 12px;
            align-items: end;
        }
        .cost-breakdown-fields > div:not(.cost-breakdown-op) label {
            display: block;
            margin-bottom: 4px;
        }
        .cost-breakdown-fields > div:not(.cost-breakdown-op) .hint {
            font-size: 11px;
            margin-top: 4px;
            min-height: 16px;
        }
        .cost-breakdown-op {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            user-select: none;
            padding-bottom: 20px;
            text-align: center;
        }
        /* Profit / Margin / Markup row */
        .profit-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 12px;
            margin-top: 12px;
            padding: 10px 0 0;
            border-top: 1px solid var(--border);
        }
        .profit-metrics label {
            display: block;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .5px;
            color: var(--text-secondary);
            font-weight: 600;
            margin-bottom: 4px;
        }
        @media (max-width: 768px) {
            .cost-breakdown-fields {
                display: flex;
                flex-direction: column;
                gap: 8px;
            }
            .cost-breakdown-op { padding: 0; }
            .profit-metrics { grid-template-columns: 1fr; }
        }

        /* ============================================================
           ORDER TOTALS TABLE (order detail page)
           ============================================================ */
        .order-totals-table {
            min-width: 280px;
            max-width: 360px;
            border-collapse: collapse;
        }
        .order-totals-table td {
            padding: 5px 0;
            font-size: 13.5px;
        }
        .order-totals-table td:first-child {
            padding-right: 24px;
        }
        .order-totals-row-total td {
            font-weight: 700;
            font-size: 15px !important;
            padding-top: 8px !important;
            border-top: 2px solid var(--border);
        }
        .order-totals-row-profit td {
            font-weight: 700;
            font-size: 15px !important;
            padding-top: 8px !important;
            border-top: 2px solid var(--border);
        }
        .order-totals-margin {
            font-size: 12px;
            font-weight: 600;
            margin-left: 6px;
        }

        /* ============================================================
           TYPEAHEAD / AUTOCOMPLETE
           ============================================================ */
        .typeahead { position: relative; }
        .typeahead-list {
            position: absolute;
            top: 100%; left: 0; right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-lg);
            max-height: 240px;
            overflow: auto;
            z-index: 50;
            display: none;
        }
        .typeahead-list button {
            width: 100%;
            text-align: left;
            padding: 10px 14px;
            border: 0;
            background: transparent;
            cursor: pointer;
            font-family: inherit;
            font-size: 13.5px;
            color: var(--text-primary);
            transition: background var(--transition-fast);
        }
        .typeahead-list button:hover { background: var(--surface-hover); }

        /* Multi-select tag chips */
        .tag-container {
            display: flex; flex-wrap: wrap; gap: 6px;
            margin-bottom: 6px; min-height: 0;
        }
        .tag-chip {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; background: rgba(59,130,246,.1);
            border: 1px solid rgba(59,130,246,.2); border-radius: 99px;
            font-size: 12.5px; font-weight: 500; color: #1d4ed8;
        }
        .tag-remove {
            background: none; border: none; cursor: pointer;
            font-size: 15px; line-height: 1; color: #3b82f6;
            padding: 0 0 0 2px; font-weight: 700;
        }
        .tag-remove:hover { color: #dc2626; }

        .ac-dd {
            position: absolute;
            left: 0; right: 0;
            top: 100%;
            margin-top: 6px;
            background: #111827;
            border: 1px solid rgba(255,255,255,.12);
            border-radius: var(--radius-md);
            box-shadow: 0 12px 30px rgba(0,0,0,.35);
            z-index: 50;
            max-height: 260px;
            overflow: auto;
        }
        .ac-dd ul { list-style: none; margin: 0; padding: 6px; }
        .ac-dd li {
            padding: 8px 10px;
            border-radius: var(--radius-sm);
            cursor: pointer;
            color: #e5e7eb;
            font-size: 14px;
            transition: background var(--transition-fast);
        }
        .ac-dd li:hover { background: rgba(255,255,255,.08); }

        /* ============================================================
           BADGES
           ============================================================ */
        .badge {
            display: inline-flex;
            align-items: center;
            padding: 2px 10px;
            border-radius: 999px;
            font-size: 11.5px;
            font-weight: 600;
            letter-spacing: .02em;
            white-space: nowrap;
        }
        .badge-blue { background: #dbeafe; color: #1e40af; }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef3c7; color: #92400e; }
        .badge-gray { background: #f1f5f9; color: #475569; }
        .badge-indigo { background: #0f1689; color: #fff; }
        .badge-orange { background: #ee4d2d; color: #fff; }
        .badge-dark { background: #64748b; color: #fff; }
        .badge-default { background: #e2e8f0; color: #475569; }

        /* ============================================================
           PAGINATION
           ============================================================ */
        .pagination-nav { margin-top: 16px; }
        .pagination { list-style: none; display: flex; flex-wrap: wrap; gap: 4px; padding: 0; margin: 0; align-items: center; }
        .pagination li a, .pagination li span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 36px;
            height: 36px;
            padding: 0 10px;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            background: var(--surface);
            color: var(--text-primary);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all var(--transition-fast);
        }
        .pagination li a:hover { background: var(--surface-hover); border-color: #cbd5e1; transform: translateY(-1px); }
        .pagination li.active span {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: 0 2px 8px rgba(59,130,246,.25);
        }
        .pagination li.disabled span { color: var(--text-muted); background: var(--surface-alt); cursor: default; }
        nav[role="navigation"] svg { display: none !important; }

        /* ============================================================
           TABS
           ============================================================ */
        .tabs { display: flex; gap: 8px; padding: 8px 0 2px 0; flex-wrap: wrap; justify-content: center; }
        .tab {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 22px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            color: var(--text-secondary);
            background: var(--surface-alt);
            border: 2px solid #cbd5e1;
            font-weight: 600;
            font-size: 14.5px;
            transition: all var(--transition-fast);
        }
        .tab:hover { background: #e2e8f0; color: var(--text-primary); border-color: #94a3b8; }
        .tab.active { background: var(--accent); color: #fff; border-color: var(--accent); box-shadow: 0 2px 6px rgba(59,130,246,.3); }
        .tab.disabled { opacity: .55; cursor: not-allowed; }

        /* ============================================================
           ORDER SHOW / DETAIL PAGES
           ============================================================ */
        .detail-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
        .detail-section {
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
            padding: 18px;
        }
        .detail-section h4 {
            margin: 0 0 12px;
            font-size: 12.5px;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .detail-body { font-size: 13.5px; line-height: 1.8; color: var(--text-secondary); }
        .detail-body strong { color: var(--text-primary); }

        .meta-bar {
            display: flex;
            gap: 16px;
            align-items: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
            padding: 12px 16px;
            background: var(--surface-alt);
            border: 1px solid var(--border);
            border-radius: var(--radius-md);
        }
        .meta-item { font-size: 13px; color: var(--text-secondary); }
        .meta-item + .meta-item { padding-left: 16px; border-left: 1px solid var(--border); }
        .meta-label { font-weight: 600; color: var(--text-primary); margin-right: 4px; }

        /* Thumbnails */
        .thumb { width: 52px; height: 52px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid rgba(0,0,0,.06); display: block; background: var(--surface-alt); }
        .thumb-sm { width: 42px; height: 42px; border-radius: 6px; }
        .thumb-placeholder { display: inline-block; border-radius: var(--radius-sm); background: var(--surface-alt); border: 1px solid var(--border); }

        /* History timeline */
        .history-item { display: flex; gap: 14px; padding: 12px 0; border-bottom: 1px solid var(--border); }
        .history-item:last-child { border-bottom: none; }
        .history-date { font-size: 12px; color: var(--text-muted); white-space: nowrap; min-width: 140px; }
        .history-status { font-weight: 600; font-size: 13px; color: var(--text-primary); }
        .history-comment { font-size: 13px; color: var(--text-secondary); margin-top: 2px; }

        /* ============================================================
           MODAL
           ============================================================ */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,.5);
            z-index: 2000;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        .modal-backdrop.active { display: flex; }
        .modal {
            background: var(--surface);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            padding: 24px;
            max-width: 600px;
            width: 100%;
            max-height: 80vh;
            overflow-y: auto;
            animation: slideInDown .25s ease;
        }
        .modal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
        .modal-header h3 { margin: 0; font-size: 1.1rem; font-weight: 600; }
        .modal-close {
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 20px;
            padding: 4px;
            line-height: 1;
            transition: color var(--transition-fast);
        }
        .modal-close:hover { color: var(--text-primary); }

        /* ============================================================
           UTILITIES
           ============================================================ */
        .d-flex { display: flex; }
        .d-none { display: none; }
        .flex-wrap { flex-wrap: wrap; }
        .items-center { align-items: center; }
        .items-start { align-items: flex-start; }
        .justify-between { justify-content: space-between; }
        .justify-end { justify-content: flex-end; }
        .justify-start { justify-content: flex-start; }
        .gap-4 { gap: 4px; }
        .gap-6 { gap: 6px; }
        .gap-8 { gap: 8px; }
        .gap-10 { gap: 10px; }
        .gap-12 { gap: 12px; }
        .gap-16 { gap: 16px; }
        .gap-20 { gap: 20px; }
        .mt-0 { margin-top: 0; }
        .mt-12 { margin-top: 12px; }
        .mt-16 { margin-top: 16px; }
        .mt-20 { margin-top: 20px; }
        .mt-24 { margin-top: 24px; }
        .mb-0 { margin-bottom: 0; }
        .mb-12 { margin-bottom: 12px; }
        .mb-16 { margin-bottom: 16px; }
        .p-0 { padding: 0; }
        .w-full { width: 100%; }
        .max-w-300 { max-width: 300px; }
        .hidden { display: none !important; }
        .truncate { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .text-sm { font-size: 13px; }
        .text-xs { font-size: 12px; }

        /* Variant row (products) */
        .variant-row td { background: var(--surface-alt); }

        /* Print */
        @media print {
            .sidebar, .sidebar-fab, .sidebar-backdrop, .topbar { display: none !important; }
            .content { margin-left: 0 !important; padding: 0 !important; }
        }

        /* ============================================================
           RESPONSIVE BREAKPOINTS
           ============================================================ */
        @media (max-width: 1280px) {
            .content { padding: 0 24px 24px; }
        }
        @media (max-width: 1024px) {
            .content { padding: 0 20px 20px; }
            .card { padding: 20px; }
            .detail-grid { gap: 16px; }
        }
        @media (max-width: 768px) {
            /* Sidebar becomes overlay */
            .sidebar {
                box-shadow: 4px 0 24px rgba(0,0,0,.3);
            }
            .content { margin-left: 0 !important; padding: 0 16px 24px; }
            .content.collapsed { margin-left: 0; }
            .topbar { padding: 16px 0 10px; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 10px; }
            .page-header-actions { width: 100%; }
            .form-grid { grid-template-columns: 1fr; }
            .detail-grid { grid-template-columns: 1fr; }
            .toolbar { flex-direction: column; align-items: stretch; }
            .toolbar form { flex-wrap: wrap; }
            .card { padding: 16px; border-radius: var(--radius-md); }
            .meta-bar { flex-direction: column; align-items: flex-start; gap: 8px; }
            .meta-item + .meta-item { padding-left: 0; border-left: none; padding-top: 8px; border-top: 1px solid var(--border); }
        }
        @media (max-width: 480px) {
            .content { padding: 0 12px 20px; }
            .card { padding: 14px; }
            .page-header h2 { font-size: 1.15rem; }
            .btn { height: 38px; padding: 0 14px; font-size: 13px; }
            .btn.small { height: 32px; padding: 0 10px; }
            .input { height: 38px; font-size: 13px; }
            table th, table td { padding: 10px 10px; font-size: 13px; }
            .pagination li a, .pagination li span { min-width: 32px; height: 32px; padding: 0 8px; font-size: 12px; }
        }
    </style>

    <!-- WYSIWYG (Summernote) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.css">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.20/dist/summernote-lite.min.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (window.jQuery && jQuery.fn && jQuery.fn.summernote) {
          jQuery('textarea.wysiwyg').summernote({
            height: 420,
            toolbar: [
              ['style', ['style']],
              ['font', ['bold', 'italic', 'underline', 'clear']],
              ['para', ['ul', 'ol', 'paragraph']],
              ['insert', ['link', 'table']],
              ['view', ['codeview']]
            ]
          });
        }
      });
    </script>

</head>
<body>
    <button id="sidebar-fab" class="sidebar-fab" type="button" aria-label="Open menu" title="Open menu">
        <svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg>
    </button>
    <div id="sidebar-backdrop" class="sidebar-backdrop"></div>

<div class="layout">
    <aside id="sidebar" class="sidebar">
        @php
            $u = auth()->user();
            $s = $appSetting ?? null;
            $companyName = $s->company_name ?? 'Company';
            $logoPath = $s->logo_path ?? null;

            $canCatalog = $u && $u->hasPermission('manage_catalog');
            $canProducts = $u && $u->hasPermission('manage_products');
            $canCategories = $u && $u->hasPermission('manage_categories');
            $canManufacturers = $u && $u->hasPermission('manage_manufacturers');
            $canOptions = $u && $u->hasPermission('manage_options');

            $canSales = $u && $u->hasPermission('manage_sales');
            $canOrders = $u && $u->hasPermission('manage_orders');
            $canOrderStatuses = $u && $u->hasPermission('manage_order_statuses');
            $canReports = $u && $u->hasPermission('manage_reports');

            $canMarketplace = $u && $u->hasPermission('manage_marketplace_api');
            $canShopee = $u && $u->hasPermission('manage_shopee');
            $canLazada = $u && $u->hasPermission('manage_lazada');
            $canShopeeOrders = $u && $u->hasPermission('manage_shopee_orders');
            $canLazadaOrders = $u && $u->hasPermission('manage_lazada_orders');
            $canTikTok = $u && $u->hasPermission('manage_tiktok');
            $canOpencart = $u && $u->hasPermission('manage_opencart');
            $canOpencartOrders = $u && $u->hasPermission('manage_opencart_orders');

            $canSettingsParent = $u && $u->hasPermission('manage_settings');
            $canUsers = $u && $u->hasPermission('manage_users');
            $canUserGroups = $u && $u->hasPermission('manage_user_groups');
            $canWebsiteSettings = $u && $u->hasPermission('manage_website_settings');
            $canErrorLog = $u && $u->hasPermission('manage_error_log');
            $canActivityLog = $u && $u->hasPermission('manage_activity_log');
        @endphp

        <div class="brand">
            <a href="{{ route('dashboard') }}" class="brand-stack" style="text-decoration:none; color:inherit;">
                @if($logoPath)
                    <img class="brand-logo" src="{{ asset('storage/'.$logoPath) }}" alt="Company Logo">
                @else
                    <div class="brand-company">{{ $companyName }}</div>
                @endif
            </a>
            <button class="toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle Sidebar" title="Toggle Sidebar">
                <span class="toggle-hamburger"><svg viewBox="0 0 24 24"><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/></svg></span>
            </button>
        </div>

        <div class="sidebar-inner">
            <nav class="sidebar-nav">
                <a href="{{ route('dashboard') }}" class="{{ request()->routeIs('dashboard') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                    <span class="label">Dashboard</span>
                </a>

                @if($canLazadaOrders)
                <a href="{{ route('lazada.orders.index', ['tab' => 'TO_SHIP']) }}" class="{{ request()->routeIs('lazada.orders.*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="label">Lazada Orders</span>
                    @if(($lazadaPendingCount ?? 0) > 0)
                        <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ $lazadaPendingCount }}</span>
                    @endif
                </a>
                @endif

                @if($canShopeeOrders)
                <a href="{{ route('shopee.orders.index', ['tab' => 'PENDING']) }}" class="{{ request()->routeIs('shopee.orders.*') ? 'active' : '' }}">
                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                    <span class="label">Shopee Orders</span>
                    @if(($shopeePendingCount ?? 0) > 0)
                        <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ $shopeePendingCount }}</span>
                    @endif
                </a>
                @endif

                @if($canOpencartOrders)
                    @foreach(($opencartStores ?? collect()) as $ocStore)
                    <a href="{{ route('opencart.orders.index', $ocStore->id) }}" class="{{ request()->routeIs('opencart.orders.*') && request()->route('store') == $ocStore->id ? 'active' : '' }}">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg></span>
                        <span class="label">{{ $ocStore->store_name }} Orders</span>
                        @if((int) ($ocStore->pending_count ?? 0) > 0)
                            <span style="margin-left:auto; background:#dc2626; color:#fff; font-weight:700; font-size:11px; min-width:20px; height:20px; line-height:20px; text-align:center; border-radius:10px; padding:0 6px; display:inline-block;">{{ (int) $ocStore->pending_count }}</span>
                        @endif
                    </a>
                    @endforeach
                @endif

                @if($canCatalog)
                <div class="nav-group">
                    <details {{ (request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*') || request()->routeIs('options.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('products.*') || request()->routeIs('categories.*') || request()->routeIs('manufacturers.*') || request()->routeIs('options.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
                            <span class="label">Catalog</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canCategories)
                                <a href="{{ route('categories.index') }}" class="{{ request()->routeIs('categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                @endif
                                @if($canProducts)
                                <a href="{{ route('products.index') }}" class="{{ request()->routeIs('products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="16.5" y1="9.4" x2="7.5" y2="4.21"/><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif
                                @if($canManufacturers)
                                <a href="{{ route('manufacturers.index') }}" class="{{ request()->routeIs('manufacturers.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/></svg></span>
                                    <span class="label">Manufacturers</span>
                                </a>
                                @endif
                                @if($canOptions)
                                <a href="{{ route('options.index') }}" class="{{ request()->routeIs('options.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Options</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canSales)
                <div class="nav-group">
                    <details {{ (request()->routeIs('orders.*') || request()->routeIs('order_statuses.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('orders.*') || request()->routeIs('order_statuses.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                            <span class="label">Sales</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canOrders)
                                <a href="{{ route('orders.index') }}" class="{{ request()->routeIs('orders.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg></span>
                                    <span class="label">Orders</span>
                                </a>
                                @endif
                                @if($canOrderStatuses)
                                <a href="{{ route('order_statuses.index') }}" class="{{ request()->routeIs('order_statuses.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/></svg></span>
                                    <span class="label">Order Statuses</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canMarketplace)
                <div class="nav-group">
                    @php
                        $mpActive = (request()->routeIs('shopee.*') && !request()->routeIs('shopee.orders.*'))
                                 || (request()->routeIs('lazada.*') && !request()->routeIs('lazada.orders.*'))
                                 || request()->routeIs('tiktok.*')
                                 || request()->routeIs('opencart.*');
                    @endphp
                    <details {{ $mpActive ? 'open' : '' }}>
                        <summary class="{{ $mpActive ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></span>
                            <span class="label">Marketplace API</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canShopee)
                                {{-- ─── Shopee ─── --}}
                                <div class="nav-sub-divider">Shopee</div>
                                <a href="{{ route('shopee.index') }}" class="{{ request()->routeIs('shopee.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                <a href="{{ route('shopee.categories.index') }}" class="{{ request()->routeIs('shopee.categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                <a href="{{ route('shopee.logistics.index') }}" class="{{ request()->routeIs('shopee.logistics.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="1" y="3" width="15" height="13"/><polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg></span>
                                    <span class="label">Logistics</span>
                                </a>
                                <a href="{{ route('shopee.profiles.index') }}" class="{{ request()->routeIs('shopee.profiles.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                                    <span class="label">Profiles</span>
                                </a>
                                <a href="{{ route('shopee.products.index') }}" class="{{ request()->routeIs('shopee.products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif

                                @if($canLazada)
                                {{-- ─── Lazada ─── --}}
                                <div class="nav-sub-divider">Lazada</div>
                                <a href="{{ route('lazada.index') }}" class="{{ request()->routeIs('lazada.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                <a href="{{ route('lazada.categories.index') }}" class="{{ request()->routeIs('lazada.categories.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg></span>
                                    <span class="label">Categories</span>
                                </a>
                                <a href="{{ route('lazada.profiles.index') }}" class="{{ request()->routeIs('lazada.profiles.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg></span>
                                    <span class="label">Profiles</span>
                                </a>
                                <a href="{{ route('lazada.products.index') }}" class="{{ request()->routeIs('lazada.products.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M13 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><polyline points="13 2 13 9 20 9"/></svg></span>
                                    <span class="label">Products</span>
                                </a>
                                @endif

                                @if($canTikTok)
                                {{-- ─── TikTok Shop ─── --}}
                                <div class="nav-sub-divider">TikTok Shop</div>
                                <a href="{{ route('tiktok.index') }}" class="{{ request()->routeIs('tiktok.index') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                @endif

                                @if($canOpencart)
                                {{-- ─── OpenCart ─── --}}
                                <div class="nav-sub-divider">OpenCart</div>
                                <a href="{{ route('opencart.index') }}" class="{{ request()->routeIs('opencart.index') || request()->routeIs('opencart.save') || request()->routeIs('opencart.test') || request()->routeIs('opencart.sync') || request()->routeIs('opencart.push') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                                    <span class="label">Settings</span>
                                </a>
                                @foreach(($opencartStores ?? collect()) as $ocStore)
                                    <a href="{{ route('opencart.profiles.index', $ocStore->id) }}" class="{{ request()->is('opencart/'.$ocStore->id.'/*') ? 'active' : '' }}">
                                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg></span>
                                        <span class="label">{{ $ocStore->store_name }}</span>
                                    </a>
                                @endforeach
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canReports)
                <div class="nav-group">
                    <details {{ request()->routeIs('reports.*') ? 'open' : '' }}>
                        <summary class="{{ request()->routeIs('reports.*') ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/></svg></span>
                            <span class="label">Reports</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                <a href="{{ route('reports.orders') }}" class="{{ request()->routeIs('reports.orders*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg></span>
                                    <span class="label">Order Profitability</span>
                                </a>
                                <a href="{{ route('reports.products') }}" class="{{ request()->routeIs('reports.products*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
                                    <span class="label">Product Profitability</span>
                                </a>
                                <a href="{{ route('reports.payouts') }}" class="{{ request()->routeIs('reports.payouts*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg></span>
                                    <span class="label">Marketplace Payouts</span>
                                </a>
                                <a href="{{ route('reports.categories') }}" class="{{ request()->routeIs('reports.categories*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg></span>
                                    <span class="label">Margin by Category</span>
                                </a>
                                <a href="{{ route('reports.fees') }}" class="{{ request()->routeIs('reports.fees*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg></span>
                                    <span class="label">Marketplace Fees</span>
                                </a>
                                <a href="{{ route('reports.inventory') }}" class="{{ request()->routeIs('reports.inventory*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg></span>
                                    <span class="label">Inventory Valuation</span>
                                </a>
                            </div>
                        </div>
                    </details>
                </div>
                @endif

                @if($canSettingsParent)
                <div class="nav-group">
                    <details {{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('error_log.*') || request()->routeIs('activity_log.*')) ? 'open' : '' }}>
                        <summary class="{{ (request()->routeIs('users.*') || request()->routeIs('user_groups.*') || request()->routeIs('settings.*') || request()->routeIs('error_log.*') || request()->routeIs('activity_log.*')) ? 'active' : '' }}">
                            <span class="nav-icon"><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></span>
                            <span class="label">Settings</span>
                            <span class="chevron"><svg viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg></span>
                        </summary>
                        <div class="nav-sub-wrap">
                            <div class="nav-sub">
                                @if($canUsers)
                                <a href="{{ route('users.index') }}" class="{{ request()->routeIs('users.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg></span>
                                    <span class="label">Users</span>
                                </a>
                                @endif
                                @if($canUserGroups)
                                <a href="{{ route('user_groups.index') }}" class="{{ request()->routeIs('user_groups.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></span>
                                    <span class="label">User Groups</span>
                                </a>
                                @endif
                                @if($canWebsiteSettings)
                                <a href="{{ route('settings.edit') }}" class="{{ request()->routeIs('settings.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></span>
                                    <span class="label">Website Setting</span>
                                </a>
                                @endif
                                @if($canErrorLog)
                                <a href="{{ route('error_log.index') }}" class="{{ request()->routeIs('error_log.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></span>
                                    <span class="label">Error Log</span>
                                </a>
                                @endif
                                @if($canActivityLog)
                                <a href="{{ route('activity_log.index') }}" class="{{ request()->routeIs('activity_log.*') ? 'active' : '' }}">
                                    <span class="nav-icon"><svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg></span>
                                    <span class="label">Activity Log</span>
                                </a>
                                @endif
                            </div>
                        </div>
                    </details>
                </div>
                @endif
            </nav>
        </div>

        @auth
            <div class="sidebar-footer">
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="logout-btn">
                        <span class="nav-icon"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg></span>
                        <span class="label">Logout</span>
                    </button>
                </form>
            </div>
        @endauth
    </aside>

    <main id="content" class="content">
        <div class="topbar">
            <div class="topbar-title">@yield('breadcrumb', 'Dashboard')</div>
            @auth
            <div style="position:relative;" id="global-search-wrap">
                <input type="text" id="global-search" class="input" placeholder="Search... (press /)" autocomplete="off" style="width:280px; padding-left:36px; font-size:13px; height:36px;">
                <svg style="position:absolute; left:10px; top:50%; transform:translateY(-50%); width:16px; height:16px; color:var(--text-muted); pointer-events:none;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                <div id="global-search-results" style="display:none; position:absolute; top:100%; right:0; margin-top:6px; width:420px; max-height:480px; overflow-y:auto; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-md); box-shadow:var(--shadow-lg); z-index:9999;"></div>
            </div>
            @endauth
        </div>

        @if(session('status'))
            <div class="alert success">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                <span>{{ session('status') }}</span>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

        @if(session('error'))
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span>{{ session('error') }}</span>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

        @if($errors->any())
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <div>
                    <ul style="margin:0; padding-left:18px;">
                        @foreach($errors->all() as $e)
                            <li>{{ $e }}</li>
                        @endforeach
                    </ul>
                </div>
                <button class="alert-close" onclick="this.parentElement.remove()" aria-label="Close">&times;</button>
            </div>
        @endif

        @php
            $__lazadaExpired = false;
            $__shopeePaused = false;
            $__shopeeExpired = false;
            $__shopeeExpiringSoon = false;
            $__shopeeExpiresInMin = null;
            $__tiktokExpired = false;
            try {
                $__tt = \App\Models\TikTokSetting::query()->first();
                $__tiktokExpired = $__tt && $__tt->expires_at && strtotime($__tt->expires_at) <= time();
            } catch (\Throwable $e) {}
            try {
                $__laz = \App\Models\LazadaSetting::query()->first();
                $__lazadaExpired = $__laz && $__laz->expires_at && strtotime($__laz->expires_at) <= time();
            } catch (\Throwable $e) {}
            try {
                $__sp = \App\Models\ShopeeSetting::query()->first();
                if ($__sp && $__sp->expires_at) {
                    $__spExp = \Carbon\Carbon::parse($__sp->expires_at);
                    if ($__spExp->lte(now())) {
                        $__shopeeExpired = true;
                    } elseif ($__spExp->lte(now()->addMinutes(30))) {
                        $__shopeeExpiringSoon = true;
                        $__shopeeExpiresInMin = (int) now()->diffInMinutes($__spExp);
                    }
                }
            } catch (\Throwable $e) {}
            $__shopeePaused = \Illuminate\Support\Facades\Cache::get('shopee_sync_paused');
        @endphp

        @if($__tiktokExpired)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>TikTok Shop:</strong> Access token expired. <a href="{{ route('tiktok.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__lazadaExpired)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Lazada:</strong> Access token expired. Order sync is paused. <a href="{{ route('lazada.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__shopeeExpired)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Shopee:</strong> Access token expired. Sync commands will fail. <a href="{{ route('shopee.index') }}">Refresh token</a></span>
            </div>
        @elseif($__shopeeExpiringSoon)
            <div class="alert warning">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                <span><strong>Shopee:</strong> Access token expires soon (in {{ $__shopeeExpiresInMin }} min). <a href="{{ route('shopee.index') }}">Refresh token</a></span>
            </div>
        @endif

        @if($__shopeePaused)
            <div class="alert danger">
                <svg style="width:18px;height:18px;flex-shrink:0;margin-top:1px;" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                <span><strong>Shopee:</strong> Order sync paused — {{ $__shopeePaused }}. <a href="{{ route('shopee.index') }}">Check settings</a></span>
            </div>
        @endif

        @yield('content')

    </main>
</div>

<script>
(function () {
    var sidebar = document.getElementById('sidebar');
    var content = document.getElementById('content');
    var btn = document.getElementById('sidebarToggle');
    var fab = document.getElementById('sidebar-fab');
    var backdrop = document.getElementById('sidebar-backdrop');
    if (!sidebar || !content || !btn) return;

    var key = 'ngd_sidebar_collapsed';
    var mobileBreak = 768;

    function isMobile() { return window.innerWidth <= mobileBreak; }

    function applyState(isCollapsed) {
        sidebar.classList.toggle('collapsed', isCollapsed);
        content.classList.toggle('collapsed', isCollapsed);

        if (isMobile()) {
            fab.classList.toggle('show', isCollapsed);
            backdrop.style.display = isCollapsed ? 'none' : 'block';
            setTimeout(function(){ backdrop.classList.toggle('visible', !isCollapsed); }, 10);
            document.body.style.overflow = isCollapsed ? '' : 'hidden';
        } else {
            fab.classList.toggle('show', isCollapsed);
            backdrop.style.display = 'none';
            backdrop.classList.remove('visible');
            document.body.style.overflow = '';
        }

        if (!isMobile()) {
            localStorage.setItem(key, isCollapsed ? '1' : '0');
        }
    }

    function initLayout() {
        if (isMobile()) {
            applyState(true);
        } else {
            var saved = localStorage.getItem(key) === '1';
            applyState(saved);
        }
    }

    initLayout();

    btn.addEventListener('click', function () {
        applyState(!sidebar.classList.contains('collapsed'));
    });

    if (fab) {
        fab.addEventListener('click', function () {
            applyState(false);
        });
    }

    if (backdrop) {
        backdrop.addEventListener('click', function () {
            applyState(true);
        });
    }

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(initLayout, 150);
    });

    // ── Smooth slide for nav <details> (accordion — one open at a time) ──
    var allNavDetails = Array.from(document.querySelectorAll('.nav-group details'));

    function closeDetails(details) {
        var wrap = details.querySelector('.nav-sub-wrap');
        if (!wrap || !details.open) return;
        var h = wrap.scrollHeight;
        wrap.style.height = h + 'px';
        wrap.offsetHeight;
        wrap.style.height = '0px';
        wrap.addEventListener('transitionend', function handler() {
            wrap.removeEventListener('transitionend', handler);
            details.open = false;
            wrap.style.height = '0px';
        });
    }

    allNavDetails.forEach(function (details) {
        var wrap = details.querySelector('.nav-sub-wrap');
        if (!wrap) return;

        // Set initial state
        if (details.open) {
            wrap.style.height = 'auto';
        } else {
            wrap.style.height = '0px';
        }

        details.querySelector('summary').addEventListener('click', function (e) {
            e.preventDefault();

            if (details.open) {
                // ── Closing ──
                closeDetails(details);
            } else {
                // ── Close all others first ──
                allNavDetails.forEach(function (other) {
                    if (other !== details) closeDetails(other);
                });

                // ── Opening ──
                details.open = true;
                var h = wrap.scrollHeight;
                wrap.style.height = '0px';
                wrap.offsetHeight;
                wrap.style.height = h + 'px';

                wrap.addEventListener('transitionend', function handler() {
                    wrap.removeEventListener('transitionend', handler);
                    wrap.style.height = 'auto';
                });
            }
        });
    });

    // ── Toast notifications (move inline alerts to fixed toast container) ──
    var toastContainer = document.createElement('div');
    toastContainer.className = 'toast-container';
    document.body.appendChild(toastContainer);

    document.querySelectorAll('.content .alert.success, .content .alert.warning').forEach(function(el) {
        // Move to toast container
        toastContainer.appendChild(el);
        el.style.marginBottom = '0';

        // Auto-dismiss after 5s
        setTimeout(function() {
            el.classList.add('toast-out');
            setTimeout(function(){ el.remove(); }, 350);
        }, 5000);
    });

    // ── Loading state on form submit buttons ──
    // Track which button was clicked so we only spinner that one
    var lastClickedSubmit = null;
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('button[type="submit"], button:not([type])');
        if (btn) lastClickedSubmit = btn;
    });

    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            // Delay loading state so other handlers can preventDefault first
            var btn = lastClickedSubmit;
            if (!btn || !form.contains(btn)) {
                var btns = form.querySelectorAll('button[type="submit"], button:not([type])');
                btn = btns.length === 1 ? btns[0] : null;
            }
            lastClickedSubmit = null;
            if (!btn) return;
            setTimeout(function() {
                if (e.defaultPrevented) return;
                btn.classList.add('is-loading');
                btn.disabled = true;
                setTimeout(function() {
                    btn.classList.remove('is-loading');
                    btn.disabled = false;
                }, 300000);
            }, 0);
        });
    });

    // ── Swipe-to-open sidebar on mobile ──
    (function () {
        var touchStartX = 0;
        var touchStartY = 0;
        var edgeZone = 30; // px from left edge
        var threshold = 60; // min swipe distance

        document.addEventListener('touchstart', function(e) {
            var t = e.touches[0];
            touchStartX = t.clientX;
            touchStartY = t.clientY;
        }, { passive: true });

        document.addEventListener('touchend', function(e) {
            var t = e.changedTouches[0];
            var dx = t.clientX - touchStartX;
            var dy = Math.abs(t.clientY - touchStartY);
            if (dy > Math.abs(dx)) return; // vertical scroll, ignore

            // Swipe right from left edge → open sidebar
            if (touchStartX < edgeZone && dx > threshold && sidebar.classList.contains('collapsed')) {
                applyState(false);
            }
            // Swipe left anywhere on sidebar → close it
            if (dx < -threshold && !sidebar.classList.contains('collapsed') && isMobile()) {
                applyState(true);
            }
        }, { passive: true });
    })();
})();
</script>

@stack('scripts')
<script>
(function(){
  function setupManufacturerSearch(){
    var input = document.getElementById('manufacturer-search');
    var dd = document.getElementById('manufacturer-search-dd');
    var list = document.getElementById('manufacturer-search-list');
    if(!input || !dd || !list) return;

    var last = '';
    var timer = null;

    function hide(){ dd.style.display='none'; }
    function show(){ dd.style.display='block'; }
    function render(items){
      list.innerHTML = '';
      if(!items || !items.length){ hide(); return; }
      items.forEach(function(it){
        var li=document.createElement('li');
        li.textContent = it.name;
        li.addEventListener('mousedown', function(e){
          e.preventDefault();
          input.value = it.name;
          hide();
        });
        list.appendChild(li);
      });
      show();
    }

    async function fetchItems(q){
      try{
        var res = await fetch('/api/catalog/manufacturers?term=' + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        return await res.json();
      }catch(e){ return []; }
    }

    input.addEventListener('input', function(){
      var q = (input.value || '').trim();
      if(q.length < 1){ hide(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items, q);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(!dd.contains(e.target) && e.target !== input){ hide(); }
    });
  }

  document.addEventListener('DOMContentLoaded', setupManufacturerSearch);
})();
</script>

<script>
(function(){
  function setupTypeahead(opts){
    var input = document.getElementById(opts.inputId);
    var hidden = opts.hiddenId ? document.getElementById(opts.hiddenId) : null;
    var list = document.getElementById(opts.listId);
    var isMulti = !!opts.multi;
    var tagContainer = isMulti ? document.getElementById(opts.tagContainerId) : null;
    if(!input || !list) return;
    if(!isMulti && !hidden) return;

    var last = '';
    var timer = null;

    function clearList(){ list.innerHTML = ''; list.style.display='none'; }
    function showList(){ list.style.display='block'; }
    function hideList(){ list.style.display='none'; }

    if(!isMulti){
      input.addEventListener('input', function(){
        hidden.value = '0';
      });
    }

    function getSelectedIds(){
      if(!tagContainer) return [];
      return Array.from(tagContainer.querySelectorAll('.tag-chip')).map(function(c){ return String(c.dataset.id); });
    }

    function addTag(id, name){
      if(!tagContainer) return;
      var chip = document.createElement('span');
      chip.className = 'tag-chip';
      chip.dataset.id = id;
      chip.innerHTML = name + '<input type="hidden" name="' + opts.hiddenName + '" value="' + id + '">' +
        '<button type="button" class="tag-remove">&times;</button>';
      chip.querySelector('.tag-remove').addEventListener('click', function(){ chip.remove(); });
      tagContainer.appendChild(chip);
    }

    function render(items, query){
      list.innerHTML = '';
      var selectedIds = isMulti ? getSelectedIds() : [];
      var filtered = isMulti ? items.filter(function(it){ return selectedIds.indexOf(String(it.id)) === -1; }) : items;
      if(!filtered || !filtered.length){
        if(query && query.length >= 1){
          var empty = document.createElement('div');
          empty.style.cssText = 'padding:8px 12px;color:#94a3b8;font-size:13px;';
          empty.textContent = isMulti && items.length > 0 ? 'All matches already selected' : 'No results found';
          list.appendChild(empty);
          showList();
        } else { hideList(); }
        return;
      }
      filtered.forEach(function(it){
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.textContent = it.name;
        btn.addEventListener('mousedown', function(e){
          e.preventDefault();
          if(isMulti){
            addTag(it.id, it.name);
            input.value = '';
            clearList();
          } else {
            input.value = it.name;
            hidden.value = String(it.id);
            clearList();
            input.classList.remove('input-error');
          }
        });
        list.appendChild(btn);
      });
      showList();
    }

    async function fetchItems(q){
      try{
        var res = await fetch(opts.url + encodeURIComponent(q), {headers:{'Accept':'application/json'}, credentials:'same-origin'});
        if(!res.ok) return [];
        var data = await res.json();
        return (data || []).map(function(r){
          return {
            id: r.id ?? r.manufacturer_id ?? r.category_id ?? r.option_id ?? r.value_id ?? r.option_value_id,
            name: r.name ?? r.path
          };
        }).filter(function(r){ return r.id !== undefined && r.name; });
      }catch(e){ return []; }
    }

    input.addEventListener('focus', function(){
      var q = (input.value || '').trim();
      if(q.length >= 1){
        last = '';
        input.dispatchEvent(new Event('keyup'));
      }
    });

    input.addEventListener('keyup', function(){
      var q = (input.value || '').trim();
      if(q.length < 1){ hideList(); return; }
      if(q === last) return;
      last = q;
      clearTimeout(timer);
      timer = setTimeout(async function(){
        var items = await fetchItems(q);
        render(items, q);
      }, 120);
    });

    document.addEventListener('click', function(e){
      if(e.target !== input && !list.contains(e.target)) hideList();
    });
  }

  document.addEventListener('DOMContentLoaded', function(){
    setupTypeahead({
      inputId: 'manufacturer_search',
      hiddenId: 'manufacturer_id',
      listId: 'manufacturer_list',
      url: '/api/catalog/manufacturers?term=',
    });
    setupTypeahead({
      inputId: 'category_search',
      listId: 'category_list',
      url: '/api/catalog/categories?term=',
      multi: true,
      tagContainerId: 'category_tags',
      hiddenName: 'category_ids[]',
    });

    // Require a valid manufacturer selection on product forms
    var mfgHidden = document.getElementById('manufacturer_id');
    var mfgInput = document.getElementById('manufacturer_search');
    if(mfgHidden && mfgInput && mfgInput.closest('form')){
      mfgInput.closest('form').addEventListener('submit', function(e){
        var val = parseInt(mfgHidden.value, 10);
        if(!val || val <= 0){
          e.preventDefault();
          mfgInput.classList.add('input-error');
          mfgInput.focus();
          mfgInput.scrollIntoView({behavior:'smooth', block:'center'});
          // Show inline error if not already shown
          var wrap = mfgInput.closest('.typeahead');
          if(wrap && !wrap.querySelector('.field-error')){
            var err = document.createElement('div');
            err.className = 'field-error';
            err.textContent = 'Please select a manufacturer from the list.';
            wrap.appendChild(err);
          }
        }
      });
      // Clear error on valid selection
      mfgInput.addEventListener('input', function(){
        mfgInput.classList.remove('input-error');
        var wrap = mfgInput.closest('.typeahead');
        if(wrap){
          var err = wrap.querySelector('.field-error');
          if(err) err.remove();
        }
      });
    }
  });
})();
</script>
<script>
(function() {
    var input = document.getElementById('global-search');
    var wrap = document.getElementById('global-search-results');
    if (!input || !wrap) return;

    var timer = null;

    // Focus on '/' key
    document.addEventListener('keydown', function(e) {
        if (e.key === '/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement.tagName)) {
            e.preventDefault();
            input.focus();
            input.select();
        }
        if (e.key === 'Escape') {
            wrap.style.display = 'none';
            input.blur();
        }
    });

    input.addEventListener('input', function() {
        clearTimeout(timer);
        var q = input.value.trim();
        if (q.length < 2) { wrap.style.display = 'none'; return; }
        timer = setTimeout(function() { doSearch(q); }, 300);
    });

    input.addEventListener('focus', function() {
        if (wrap.innerHTML.trim() !== '' && input.value.trim().length >= 2) {
            wrap.style.display = 'block';
        }
    });

    document.addEventListener('click', function(e) {
        if (!document.getElementById('global-search-wrap').contains(e.target)) {
            wrap.style.display = 'none';
        }
    });

    function doSearch(q) {
        fetch('/search?q=' + encodeURIComponent(q), {
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var results = data.results || [];
            if (results.length === 0) {
                wrap.innerHTML = '<div style="padding:16px; text-align:center; color:var(--text-muted); font-size:13px;">No results found</div>';
                wrap.style.display = 'block';
                return;
            }

            var html = '';
            var lastCat = '';
            results.forEach(function(r) {
                if (r.category !== lastCat) {
                    lastCat = r.category;
                    html += '<div style="padding:8px 14px 4px; font-size:11px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em;">' + r.category + '</div>';
                }
                html += '<a href="' + r.url + '" style="display:block; padding:8px 14px; text-decoration:none; color:var(--text-primary); transition:background .15s;"'
                    + ' onmouseenter="this.style.background=\'var(--surface-hover)\'" onmouseleave="this.style.background=\'transparent\'">'
                    + '<div style="font-size:13px; font-weight:500;">' + escHtml(r.label) + '</div>'
                    + (r.sub ? '<div style="font-size:12px; color:var(--text-secondary);">' + escHtml(r.sub) + '</div>' : '')
                    + '</a>';
            });

            wrap.innerHTML = html;
            wrap.style.display = 'block';
        })
        .catch(function() {
            wrap.innerHTML = '<div style="padding:16px; text-align:center; color:var(--danger); font-size:13px;">Search failed</div>';
            wrap.style.display = 'block';
        });
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }
})();
</script>
</body>
</html>
