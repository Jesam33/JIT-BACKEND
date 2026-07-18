<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'LMS Admin')</title>
    <style>
        :root {
            --bg: #f3f5f9;
            --panel: #ffffff;
            --panel-alt: #f8fafc;
            --ink: #1d2a39;
            --muted: #728194;
            --line: #e1e7ef;
            --sidebar: #1f2d3d;
            --sidebar-line: rgba(255, 255, 255, 0.08);
            --sidebar-ink: #edf3f8;
            --sidebar-muted: #9bafc2;
            --accent: #c1121f;
            --accent-dark: #9b0f19;
            --accent-soft: rgba(193, 18, 31, 0.10);
            --success: #1f9d55;
            --success-soft: rgba(31, 157, 85, 0.10);
            --warning-soft: rgba(217, 119, 6, 0.12);
            --warning: #b45309;
            --danger-soft: rgba(185, 28, 28, 0.12);
            --danger: #b91c1c;
            --shadow: 0 14px 36px rgba(18, 29, 45, 0.08);
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font-family: var(--bb-body-font-family, "Inter Var", Inter, -apple-system, BlinkMacSystemFont, "San Francisco", "Segoe UI", Roboto, "Helvetica Neue", sans-serif);
        }

        a { color: inherit; }

        .page {
            padding: 0;
        }

        .frame {
            width: 100%;
            min-height: 100vh;
            display: grid;
            grid-template-columns: 260px minmax(0, 1fr);
        }

        .sidebar {
            background: linear-gradient(180deg, var(--sidebar) 0%, #223244 100%);
            border-right: 1px solid var(--sidebar-line);
            display: flex;
            flex-direction: column;
            color: var(--sidebar-ink);
        }

        .brand {
            padding: 22px 18px 18px;
            border-bottom: 1px solid var(--sidebar-line);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .brand-mark {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--accent), #ef4444);
            color: #fff;
            font-weight: 800;
            display: grid;
            place-items: center;
            font-size: 14px;
            box-shadow: 0 10px 20px rgba(193, 18, 31, 0.26);
        }

        .brand-copy small {
            display: block;
            color: var(--sidebar-muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
            margin-bottom: 3px;
        }

        .brand-copy strong {
            display: block;
            font-size: 14px;
            font-weight: 700;
        }

        .nav {
            padding: 12px;
            display: grid;
            gap: 4px;
        }

        .nav-section {
            margin: 12px 10px 6px;
            color: var(--sidebar-muted);
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.14em;
        }

        .nav-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            padding: 11px 12px;
            border-radius: 12px;
            text-decoration: none;
            color: var(--sidebar-ink);
            font-size: 14px;
            transition: background 0.18s ease;
        }

        .nav-item:hover {
            background: rgba(255, 255, 255, 0.06);
        }

        .nav-item.active {
            background: var(--accent);
            color: #fff;
            font-weight: 700;
        }

        .nav-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-icon {
            width: 18px;
            text-align: center;
            color: var(--sidebar-muted);
            font-size: 13px;
            font-weight: 700;
        }

        .nav-item.active .nav-icon {
            color: #fff;
        }

        .sidebar-footer {
            margin-top: auto;
            border-top: 1px solid var(--sidebar-line);
            padding: 14px 16px;
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
            letter-spacing: 0.12em;
        }

        .footer-copy strong {
            display: block;
            font-size: 13px;
            color: #fff;
        }

        .workspace {
            background: linear-gradient(180deg, #f8fafc 0%, #f2f5f9 100%);
            display: flex;
            flex-direction: column;
            min-width: 0;
        }

        .toolbar {
            padding: 18px 24px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            background: #fff;
        }

        .toolbar h1 {
            margin: 0;
            font-size: 24px;
            letter-spacing: -0.03em;
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
            border-radius: 8px;
            padding: 8px 11px;
            font-size: 12px;
            text-decoration: none;
        }

        .chip strong {
            color: #30465b;
        }

        .action.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #fff;
        }

        .action.primary:hover {
            background: var(--accent-dark);
        }

        .content {
            padding: 20px 24px 28px;
            display: grid;
            gap: 18px;
        }

        .panel,
        .mini-panel,
        .stat-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 12px;
            box-shadow: var(--shadow);
        }

        .panel {
            overflow: hidden;
        }

        .panel-head {
            padding: 14px 16px;
            border-bottom: 1px solid var(--line);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            background: var(--panel-alt);
        }

        .panel-title {
            color: #4f6478;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .panel-link {
            color: var(--accent);
            font-size: 11px;
            text-decoration: none;
            font-weight: 700;
        }

        .panel-empty {
            padding: 20px 16px;
            color: var(--muted);
            font-size: 14px;
        }

        .course-state {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 7px;
            border-radius: 999px;
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

        @media (max-width: 940px) {
            .frame {
                grid-template-columns: 1fr;
            }

            .sidebar {
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
                padding: 14px;
            }
        }
    </style>
    @yield('head')
</head>
<body>
    <div class="page">
        <div class="frame">
            <aside class="sidebar">
                <div class="brand">
                    <div class="brand-mark">J</div>
                    <div class="brand-copy">
                        <small>Jorsas Tech</small>
                        <strong>Jorsas LMS</strong>
                    </div>
                </div>

                <div class="nav">
                    <a href="{{ route('admin.lms.index') }}" class="nav-item {{ ($activeLmsPage ?? 'dashboard') === 'dashboard' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">□</div>
                            <span>Dashboard</span>
                        </div>
                        <span>&rsaquo;</span>
                    </a>

                    <div class="nav-section">Student Operations</div>

                    <a href="{{ route('admin.lms.students.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'students' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">◎</div>
                            <span>All Students</span>
                        </div>
                        <span>{{ $studentCount ?? 0 }}</span>
                    </a>

                    <a href="{{ route('lms.courses.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'courses' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">▤</div>
                            <span>Courses</span>
                        </div>
                        <span>&rsaquo;</span>
                    </a>

                    <a href="{{ route('lms.tracks.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'tracks' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">◇</div>
                            <span>Tracks & Cohorts</span>
                        </div>
                        <span>{{ $tracks ?? 0 }}</span>
                    </a>

                    <div class="nav-section">Agent Operations</div>

                    <a href="{{ route('admin.lms.agents.index') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'agents' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">★</div>
                            <span>Agent Applications</span>
                        </div>
                        <span>&rsaquo;</span>
                    </a>

                    <div class="nav-section">Staff Operations</div>

                    <a href="{{ route('lms.teachers.create') }}" class="nav-item {{ ($activeLmsPage ?? '') === 'teachers' ? 'active' : '' }}">
                        <div class="nav-left">
                            <div class="nav-icon">◌</div>
                            <span>Staff Accounts</span>
                        </div>
                        <span>&rsaquo;</span>
                    </a>
                </div>

                    <div class="sidebar-footer">
                    <div class="footer-copy">
                        <small>LMS status</small>
                        <strong>{{ $studentCount ?? 0 }} learner{{ ($studentCount ?? 0) === 1 ? '' : 's' }}</strong>
                    </div>
                    <div class="course-state active">Live</div>
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
    </div>
</body>
</html>
