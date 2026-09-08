<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'LMS Admin')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg: #f6f7f9;
            --panel: #ffffff;
            --panel-alt: #fafbfc;
            --ink: #16233a;
            --muted: #6b7a8d;
            --line: #e8ecf1;
            --sidebar: #0f172a;
            --sidebar-line: rgba(255, 255, 255, 0.07);
            --sidebar-ink: #e8eef6;
            --sidebar-muted: #8496ab;
            --accent: #ed180d;
            --accent-dark: #c81207;
            --accent-soft: rgba(237, 24, 13, 0.08);
            --success: #12915a;
            --success-soft: rgba(18, 145, 90, 0.10);
            --warning-soft: rgba(217, 119, 6, 0.12);
            --warning: #b45309;
            --danger-soft: rgba(185, 28, 28, 0.10);
            --danger: #b91c1c;
            --shadow: 0 1px 2px rgba(16, 24, 40, 0.04), 0 8px 24px rgba(16, 24, 40, 0.06);
            --radius: 14px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: "Plus Jakarta Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", sans-serif;
            font-size: 14px;
            -webkit-font-smoothing: antialiased;
        }

        a { color: inherit; }

        .frame {
            width: 100%;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 256px minmax(0, 1fr);
        }

        /* ─── Sidebar ─────────────────────────────────────────────── */
        .sidebar {
            background: var(--sidebar);
            border-right: 1px solid var(--sidebar-line);
            display: flex;
            flex-direction: column;
            color: var(--sidebar-ink);
            position: sticky;
            top: 0;
            height: 100vh;
            overflow-y: auto;
        }

        .brand {
            padding: 18px 20px;
            border-bottom: 1px solid var(--sidebar-line);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .brand-logo {
            display: block;
            height: 30px;
            width: auto;
        }

        .nav {
            padding: 14px 12px;
            display: grid;
            gap: 2px;
        }

        .nav-section {
            margin: 16px 12px 6px;
            color: var(--sidebar-muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.16em;
            font-weight: 700;
        }

        .nav-section:first-child {
            margin-top: 2px;
        }

        .nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 9px 12px;
            border-radius: 10px;
            text-decoration: none;
            color: var(--sidebar-muted);
            font-size: 13.5px;
            font-weight: 500;
            transition: background 0.15s ease, color 0.15s ease;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--sidebar-ink);
        }

        .nav-item.active {
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            box-shadow: 0 6px 16px rgba(237, 24, 13, 0.32);
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 11px;
            min-width: 0;
        }

        .nav-icon {
            width: 18px;
            height: 18px;
            flex: none;
            display: grid;
            place-items: center;
        }

        .nav-icon svg {
            width: 17px;
            height: 17px;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }

        .nav-count {
            min-width: 22px;
            padding: 2px 7px;
            border-radius: 7px;
            background: rgba(255, 255, 255, 0.07);
            color: var(--sidebar-muted);
            font-size: 11px;
            font-weight: 700;
            text-align: center;
        }

        .nav-item.active .nav-count {
            background: rgba(255, 255, 255, 0.2);
            color: #fff;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid var(--sidebar-line);
            padding: 16px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .footer-copy small {
            display: block;
            color: var(--sidebar-muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-weight: 700;
        }

        .footer-copy strong {
            display: block;
            margin-top: 2px;
            font-size: 13px;
            color: #fff;
        }

        .sidebar-account {
            border-top: 1px solid var(--sidebar-line);
            padding: 14px 16px;
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .account-avatar {
            width: 34px;
            height: 34px;
            flex: none;
            border-radius: 10px;
            background: var(--accent);
            display: grid;
            place-items: center;
            box-shadow: 0 4px 12px rgba(237, 24, 13, 0.3);
        }

        .account-avatar svg {
            width: 17px;
            height: 17px;
            stroke: #fff;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }

        .account-copy {
            line-height: 1.25;
            min-width: 0;
        }

        .account-copy strong {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
        }

        .account-copy span {
            display: block;
            margin-top: 1px;
            color: var(--sidebar-muted);
            font-size: 11px;
        }

        .account-logout {
            width: 30px;
            height: 30px;
            flex: none;
            margin-left: auto;
            display: grid;
            place-items: center;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 8px;
            color: var(--sidebar-muted);
            transition: border-color 0.15s ease, color 0.15s ease;
        }

        .account-logout:hover {
            border-color: rgba(255, 255, 255, 0.3);
            color: #ff8f88;
        }

        .account-logout svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }

        /* ─── Workspace ───────────────────────────────────────────── */
        .workspace {
            background: var(--bg);
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .toolbar {
            padding: 20px 28px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: rgba(255, 255, 255, 0.86);
            backdrop-filter: blur(8px);
            position: sticky;
            top: 0;
            z-index: 20;
        }

        .toolbar h1 {
            margin: 0;
            font-size: 21px;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .toolbar-copy p {
            margin: 4px 0 0;
            color: var(--muted);
            font-size: 13px;
        }

        .toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        .chip,
        .action {
            border: 1px solid var(--line);
            background: #fff;
            color: #557082;
            border-radius: 10px;
            padding: 8px 12px;
            font-size: 12px;
            font-weight: 600;
            font-family: inherit;
            text-decoration: none;
            transition: border-color 0.15s ease, background 0.15s ease;
        }

        .chip strong {
            color: #30465b;
            font-weight: 700;
        }

        .action:hover {
            border-color: #cfd8e2;
        }

        .action.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
            box-shadow: 0 6px 16px rgba(237, 24, 13, 0.28);
        }

        .action.primary:hover {
            background: var(--accent-dark);
            border-color: var(--accent-dark);
        }

        .content {
            padding: 24px 28px 32px;
            display: grid;
            gap: 18px;
        }

        .panel,
        .mini-panel,
        .stat-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }

        .panel {
            overflow: hidden;
        }

        .panel-head {
            padding: 15px 18px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--panel-alt);
        }

        .panel-title {
            color: #4f6478;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 800;
        }

        .panel-link {
            color: var(--accent);
            font-size: 12px;
            text-decoration: none;
            font-weight: 700;
        }

        .panel-empty {
            padding: 24px 18px;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .course-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 9px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
        }

        .course-state.active {
            background: var(--success-soft);
            color: var(--success);
        }

        .course-state.inactive {
            background: #eef2f6;
            color: #7a8c9c;
        }

        .course-state.warning {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .course-state.danger {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .panel-body {
            padding: 18px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table th,
        .data-table td {
            text-align: left;
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
            font-variant-numeric: tabular-nums;
        }

        .data-table th {
            color: #627a8f;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 800;
            background: var(--panel-alt);
            white-space: nowrap;
        }

        .data-table td strong {
            font-size: 14px;
        }

        .data-table td.num,
        .data-table th.num {
            white-space: nowrap;
        }

        .data-table tbody tr:last-child td {
            border-bottom: 0;
        }

        .data-table tr.total-row td {
            font-weight: 800;
            background: var(--panel-alt);
            border-top: 2px solid var(--line);
            border-bottom: 0;
        }

        .row-sub {
            color: var(--muted);
            font-size: 12px;
            font-weight: 500;
            margin-top: 2px;
        }

        @media (max-width: 940px) {
            .frame {
                grid-template-columns: 1fr;
            }

            .sidebar {
                position: static;
                height: auto;
                border-right: 0;
                border-bottom: 1px solid var(--sidebar-line);
            }
        }

        @media (max-width: 720px) {
            .toolbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .toolbar-actions {
                width: 100%;
            }

            .content {
                padding: 16px;
            }
        }
    </style>
    @yield('head')
</head>
<body>
    <div class="frame">
        <aside class="sidebar">
            <div class="brand">
                <img src="/storage/backgrounds/jorsas-logo-white.png" alt="Jorsastech" class="brand-logo">
            </div>

            <div class="nav">
                <a href="{{ route('admin.lms.index') }}" class="nav-item {{ ($activeLmsPage ?? 'dashboard') === 'dashboard' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/></svg>
                        </div>
                        <span>Dashboard</span>
                    </div>
                </a>

                <div class="nav-section">Student Operations</div>

                <a href="{{ route('admin.lms.students.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'students' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        </div>
                        <span>All Students</span>
                    </div>
                    <span class="nav-count">{{ $studentCount ?? 0 }}</span>
                </a>

                <a href="{{ route('lms.courses.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'courses' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
                        </div>
                        <span>Courses</span>
                    </div>
                </a>

                <a href="{{ route('lms.tracks.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'tracks' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M12 2 2 7l10 5 10-5-10-5z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/></svg>
                        </div>
                        <span>Tracks &amp; Cohorts</span>
                    </div>
                    <span class="nav-count">{{ $tracks ?? 0 }}</span>
                </a>

                <a href="{{ route('admin.lms.intake.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'registrations' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                        </div>
                        <span>Registrations</span>
                    </div>
                </a>

                <div class="nav-section">Agent Operations</div>

                <a href="{{ route('admin.lms.agents.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'agents' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                        </div>
                        <span>Agent Applications</span>
                    </div>
                </a>

                <a href="{{ route('admin.lms.agents.withdrawals') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'agent-withdrawals' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4Z"/></svg>
                        </div>
                        <span>Withdrawal Requests</span>
                    </div>
                </a>

                <div class="nav-section">Staff Operations</div>

                <a href="{{ route('lms.teachers.create') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'teachers' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        </div>
                        <span>Staff Accounts</span>
                    </div>
                </a>

                <a href="{{ route('lms.classrooms.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'classrooms' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                        </div>
                        <span>Classrooms</span>
                    </div>
                </a>

                <a href="{{ route('admin.lms.institutes.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'institutes' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4"/><path d="M8 6h.01M16 6h.01M12 6h.01M8 10h.01M16 10h.01M12 10h.01M8 14h.01M16 14h.01M12 14h.01"/></svg>
                        </div>
                        <span>Registered Institutes</span>
                    </div>
                    <span class="nav-count">{{ $tenantCount ?? 0 }}</span>
                </a>

                <div class="nav-section">Platform</div>

                <a href="{{ route('admin.lms.announcements.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'announcements' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/></svg>
                        </div>
                        <span>Announcements</span>
                    </div>
                </a>

                <a href="{{ route('admin.lms.forums.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'forums' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
                        </div>
                        <span>CEO's Forum</span>
                    </div>
                </a>

                <a href="{{ route('admin.lms.transactions.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'transactions' ? 'active' : '' }}">
                    <div class="nav-left">
                        <div class="nav-icon">
                            <svg viewBox="0 0 24 24"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8"/></svg>
                        </div>
                        <span>Transactions &amp; Revenue</span>
                    </div>
                </a>
            </div>

            <div class="sidebar-footer">
                <div class="footer-copy">
                    <small>LMS status</small>
                    <strong>{{ $studentCount ?? 0 }} learner{{ ($studentCount ?? 0) === 1 ? '' : 's' }}</strong>
                </div>
                <div class="course-state active">Live</div>
            </div>

            <div class="sidebar-account">
                <div class="account-avatar">
                    <svg viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                </div>
                <div class="account-copy">
                    <strong>Administrator</strong>
                    <span>Super admin</span>
                </div>
                <a href="{{ route('access.logout') }}" class="account-logout" title="Sign out">
                    <svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                </a>
            </div>
        </aside>

        <main class="workspace">
            <div class="toolbar">
                <div class="toolbar-copy">
                    <h1>@yield('toolbar_title', 'LMS Dashboard')</h1>
                    <p>@yield('toolbar_text', 'Manage intake, staff setup, courses, cohorts and learner delivery from one LMS workspace.')</p>
                </div>
                <div class="toolbar-actions">
                    @yield('toolbar_actions')
                </div>
            </div>

            <div class="content">
                @yield('content')
            </div>
        </main>
    </div>
    @stack('scripts')
</body>
</html>
