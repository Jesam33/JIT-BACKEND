@extends('admin.lms.layout')

@section('title', 'LMS Dashboard')
@php($activeLmsPage = 'dashboard')

@section('head')
    <style>
        .stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            padding: 16px;
            display: grid;
            gap: 10px;
        }

        .stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-label {
            color: #4f6478;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .stat-link {
            color: var(--accent);
            font-size: 11px;
            text-decoration: none;
            font-weight: 700;
        }

        .stat-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .stat-value {
            font-size: 42px;
            font-weight: 800;
            line-height: 0.95;
            letter-spacing: -0.05em;
        }

        .delta {
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .delta.up {
            background: var(--success-soft);
            color: var(--success);
        }

        .delta.down {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .stat-meta {
            display: flex;
            gap: 18px;
            color: #8293a3;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-weight: 700;
        }

        .stat-meta span {
            display: block;
            margin-top: 3px;
            color: var(--ink);
            font-size: 18px;
            letter-spacing: -0.03em;
        }

        .stat-spark {
            height: 36px;
            display: flex;
            align-items: end;
            gap: 3px;
        }

        .stat-spark i {
            display: block;
            flex: 1;
            background: linear-gradient(180deg, #d8e2ec, #c4d2df);
            border-radius: 999px;
            min-width: 5px;
        }

        .main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(280px, 0.8fr);
            gap: 16px;
        }

        .chart-wrap {
            padding: 16px;
        }

        .chart-shell {
            position: relative;
            height: 330px;
        }

        .chart-svg {
            width: 100%;
            height: 270px;
            display: block;
        }

        .chart-grid line {
            stroke: #ebf0f4;
            stroke-width: 1;
        }

        .chart-grid text,
        .chart-x text {
            fill: #8fa0af;
            font-size: 11px;
            font-family: inherit;
        }

        .chart-area {
            fill: url(#activityFill);
        }

        .chart-line {
            fill: none;
            stroke: var(--accent);
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .chart-point {
            fill: #fff;
            stroke: var(--accent);
            stroke-width: 3;
        }

        .chart-summary {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 10px;
            color: #5c7286;
            font-size: 12px;
            font-weight: 700;
        }

        .chart-summary span b {
            color: var(--ink);
            margin-left: 4px;
            font-size: 18px;
            letter-spacing: -0.03em;
        }

        .leaderboard-table,
        .courses-table {
            width: 100%;
            border-collapse: collapse;
        }

        .leaderboard-table th,
        .leaderboard-table td,
        .courses-table th,
        .courses-table td {
            text-align: left;
            padding: 11px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 13px;
        }

        .leaderboard-table th,
        .courses-table th {
            color: #627a8f;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
            background: #fbfdff;
        }

        .leaderboard-table td strong,
        .courses-table td strong {
            font-size: 14px;
        }

        .courses-panel {
            min-height: 220px;
        }

        .progress-track {
            width: 100%;
            height: 8px;
            border-radius: 999px;
            background: #edf2f6;
            overflow: hidden;
            margin-top: 6px;
        }

        .progress-bar {
            height: 100%;
            border-radius: 999px;
            background: linear-gradient(90deg, var(--accent), #ef4444);
        }

        .footer-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .mini-panel {
            padding: 16px;
        }

        .mini-panel h3 {
            margin: 0 0 8px;
            font-size: 14px;
        }

        .mini-panel p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .mini-panel strong {
            display: block;
            margin-top: 12px;
            font-size: 24px;
            letter-spacing: -0.04em;
        }

        @media (max-width: 1180px) {
            .stats,
            .footer-grid {
                grid-template-columns: 1fr;
            }

            .main-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 720px) {
            .stat-body {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
@endsection

@section('toolbar_title', 'LMS Management Dashboard')
@section('toolbar_text', 'Admin manages student accounts, course setup, track assignment, staff provisioning, and overall learning delivery health.')

@section('toolbar_actions')
    <div class="chip">Period <strong>Last 12 Months</strong></div>
    <div class="chip">Students <strong>{{ $studentCount }}</strong></div>
    <div class="chip">Data <strong>Live</strong></div>
    <a href="{{ route('admin.lms.students.index') }}" class="action primary">View Students</a>
@endsection

@section('content')
    <section class="stats">
        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Student Completion</div>
                <a href="{{ route('lms.courses.index') }}" class="stat-link">Course Management</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">{{ number_format($completionRate, 1) }}%</div>
                <div class="delta {{ $completionRate >= 50 ? 'up' : 'down' }}">
                    {{ $completedTaskSlots }}/{{ $assignedTaskSlots }}
                </div>
            </div>
            <div class="stat-meta">
                <div>Assigned<span>{{ $assignedTaskSlots }}</span></div>
                <div>Completed<span>{{ $completedTaskSlots }}</span></div>
            </div>
            <div class="stat-spark" aria-hidden="true">
                @foreach ($monthlyActiveLearners as $point)
                    <i style="height: {{ max(18, min(36, $point['value'] * 6)) }}px;"></i>
                @endforeach
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Onboarding Activation</div>
                <a href="{{ route('admin.lms.students.index') }}" class="stat-link">All Students</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">{{ number_format($activationRate, 1) }}%</div>
                <div class="delta {{ $activationRate >= 50 ? 'up' : 'down' }}">
                    {{ $onboardedStudents }}/{{ $approvedRegistrations }}
                </div>
            </div>
            <div class="stat-meta">
                <div>Approved<span>{{ $approvedRegistrations }}</span></div>
                <div>Onboarded<span>{{ $onboardedStudents }}</span></div>
            </div>
            <div class="stat-spark" aria-hidden="true">
                @foreach ($monthlyActiveLearners as $point)
                    <i style="height: {{ max(16, min(36, ($point['value'] + 1) * 5)) }}px;"></i>
                @endforeach
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Track Placement</div>
                <a href="{{ route('lms.tracks.index') }}" class="stat-link">Cohort Assignment</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">{{ number_format($enrollmentRate, 1) }}%</div>
                <div class="delta {{ $enrollmentRate >= 50 ? 'up' : 'down' }}">
                    {{ $enrolledStudents }}/{{ $studentCount }}
                </div>
            </div>
            <div class="stat-meta">
                <div>Learners<span>{{ $studentCount }}</span></div>
                <div>Enrolled<span>{{ $enrolledStudents }}</span></div>
            </div>
            <div class="stat-spark" aria-hidden="true">
                @foreach ($monthlyActiveLearners as $point)
                    <i style="height: {{ max(14, min(36, ($point['value'] + 2) * 4)) }}px;"></i>
                @endforeach
            </div>
        </article>
    </section>

    <section class="main-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Monthly Student Activity</div>
                <a href="{{ route('admin.lms.students.index') }}" class="panel-link">Student overview</a>
            </div>
            <div class="chart-wrap">
                <div class="chart-shell">
                    <svg class="chart-svg" viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" role="img" aria-label="Monthly active learners chart">
                        <defs>
                            <linearGradient id="activityFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#c1121f" stop-opacity="0.22"></stop>
                                <stop offset="100%" stop-color="#c1121f" stop-opacity="0.03"></stop>
                            </linearGradient>
                        </defs>

                        <g class="chart-grid">
                            @foreach ($chartTicks as $tick)
                                <line x1="0" y1="{{ $tick['y'] }}" x2="{{ $chartWidth }}" y2="{{ $tick['y'] }}"></line>
                                <text x="0" y="{{ $tick['y'] - 6 }}">{{ $tick['value'] }}</text>
                            @endforeach
                        </g>

                        <polygon class="chart-area" points="{{ $chartAreaPoints }}"></polygon>
                        <polyline class="chart-line" points="{{ $chartLinePoints }}"></polyline>

                        @foreach ($chartPoints as $point)
                            <circle class="chart-point" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5"></circle>
                        @endforeach

                        <g class="chart-x">
                            @foreach ($chartPoints as $point)
                                <text x="{{ $point['x'] }}" y="{{ $chartBaseline + 18 }}" text-anchor="middle">{{ $point['label'] }}</text>
                            @endforeach
                        </g>
                    </svg>
                </div>

                <div class="chart-summary">
                    <span>Courses<b>{{ $courses }}</b></span>
                    <span>Teachers<b>{{ $teachers }}</b></span>
                    <span>Tracks<b>{{ $tracks }}</b></span>
                    <span>Classrooms<b>{{ $classrooms }}</b></span>
                </div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Student Progress Snapshot</div>
                <a href="{{ route('lms.teachers.create') }}" class="panel-link">Manage staff</a>
            </div>

            @if ($leaderboard->isEmpty())
                <div class="panel-empty">No student progress is available yet. This table fills up once students are onboarded and staff begin pushing tasks to learners.</div>
            @else
                <table class="leaderboard-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>In Progress</th>
                            <th>Complete</th>
                            <th>Progress</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($leaderboard as $entry)
                            <tr>
                                <td><strong>{{ $entry['name'] }}</strong></td>
                                <td>{{ $entry['in_progress'] }}</td>
                                <td>{{ $entry['complete'] }}</td>
                                <td>{{ $entry['progress'] }}%</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </article>
    </section>

    <section class="panel courses-panel" id="courses">
        <div class="panel-head">
            <div class="panel-title">Course Delivery Snapshot</div>
            <a href="{{ route('lms.courses.index') }}" class="panel-link">Course load</a>
        </div>

        @if ($popularCourses->isEmpty())
            <div class="panel-empty">No LMS courses exist yet. Admin needs to create courses first, then students can register and staff can begin delivery.</div>
        @else
            <table class="courses-table">
                <thead>
                    <tr>
                        <th>Course Name</th>
                        <th>Created</th>
                        <th>Last Update</th>
                        <th>Enrolled</th>
                        <th>Started</th>
                        <th>Completed</th>
                        <th>Average Progress</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($popularCourses as $course)
                        <tr>
                            <td>
                                <strong>{{ $course['title'] }}</strong>
                                <div class="course-state {{ $course['is_active'] ? 'active' : 'inactive' }}" style="margin-top: 6px; width: fit-content;">
                                    {{ $course['is_active'] ? 'Active' : 'Inactive' }}
                                </div>
                            </td>
                            <td>{{ optional($course['created_at'])->format('d-m-Y') ?? '-' }}</td>
                            <td>{{ optional($course['updated_at'])->format('d-m-Y') ?? '-' }}</td>
                            <td>{{ $course['enrolled'] }}</td>
                            <td>{{ $course['started'] }}</td>
                            <td>{{ $course['completed'] }}</td>
                            <td style="min-width: 160px;">
                                {{ $course['average_progress'] }}%
                                <div class="progress-track">
                                    <div class="progress-bar" style="width: {{ max(0, min(100, $course['average_progress'])) }}%;"></div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="footer-grid" id="tracks">
        <article class="mini-panel">
            <h3>Total students</h3>
            <p>All registered learners in the LMS system, including those still onboarding.</p>
            <strong>{{ $studentCount }}</strong>
        </article>

        <article class="mini-panel">
            <h3>Onboarded learners</h3>
            <p>Students who completed onboarding and can access their portal and track enrollment.</p>
            <strong>{{ $onboardedStudents }}</strong>
        </article>

        <article class="mini-panel">
            <h3>Staff delivery structure</h3>
            <p>Tracks and courses configured so staff can teach, grade, message and manage live sessions.</p>
            <strong>{{ $tracks }}</strong>
        </article>
    </section>
@endsection
