@extends('admin.lms.layout')

@section('title', 'LMS Courses')
@php ($activeLmsPage = 'courses') @endphp

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 18px; }
        .row { margin-bottom: 14px; }
        .row:last-child { margin-bottom: 0; }
        label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #4f6478; }
        input, textarea, select {
            width: 100%;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 10px 12px;
            font: inherit;
            font-size: 13px;
            color: var(--ink);
            background: #fff;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        input:hover, textarea:hover, select:hover { border-color: #cfd8e2; }
        input:focus, textarea:focus, select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        textarea { min-height: 120px; resize: vertical; }
        .check-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .check-row label {
            display: inline-flex;
            gap: 8px;
            align-items: center;
            margin: 0;
            font-size: 13px;
            font-weight: 600;
            color: var(--ink);
        }
        .check-row input { width: auto; accent-color: var(--accent); }
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            background: var(--accent);
            color: #fff;
            border: 0;
            border-radius: 10px;
            padding: 10px 14px;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 6px 16px rgba(237, 24, 13, 0.28);
            transition: background 0.15s ease;
        }
        .btn:hover { background: var(--accent-dark); }
        .btn svg, .del svg {
            width: 14px;
            height: 14px;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        .del {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border: 1px solid transparent;
            background: var(--danger-soft);
            color: var(--danger);
            border-radius: 10px;
            padding: 7px 12px;
            font: inherit;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .del:hover { background: rgba(185, 28, 28, 0.16); }
        .ok {
            display: flex;
            align-items: center;
            gap: 9px;
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            font-weight: 600;
            background: var(--success-soft);
            color: var(--success);
        }
        .ok svg {
            width: 16px;
            height: 16px;
            flex: none;
            stroke: currentColor;
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
        }
        .stack { display: grid; gap: 18px; }
        .link-cell a { color: var(--accent); font-weight: 700; text-decoration: none; }
        @media (max-width: 1080px) { .grid { grid-template-columns: 1fr; } }
    </style>
@endsection

@section('toolbar_title', 'Course Management')
@section('toolbar_text', 'Create the course records students choose during onboarding, then monitor delivery footprint across classrooms, tasks, and enrollments.')

@section('toolbar_actions')
    <div class="chip">Courses <strong>{{ $coursesList->count() }}</strong></div>
    <a href="{{ route('admin.lms.index') }}" class="action">Back to dashboard</a>
@endsection

@section('content')
    <div class="stack">
        @if (session('status'))
            <div class="ok">
                <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                {{ session('status') }}
            </div>
        @endif

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Create course</div>
                </div>
                <form method="POST" action="{{ route('lms.courses.store') }}" class="panel-body">
                    @csrf
                    <div class="row">
                        <label for="title">Course title</label>
                        <input id="title" name="title" type="text" required>
                    </div>
                    <div class="row">
                        <label for="description">Description</label>
                        <textarea id="description" name="description"></textarea>
                    </div>
                    <div class="row">
                        <label for="price">Price (₦)</label>
                        <input id="price" name="price" type="number" step="0.01" min="0" value="0" required>
                    </div>
                    <div class="row">
                        <label for="max_students">Max Students (0 = unlimited)</label>
                        <input id="max_students" name="max_students" type="number" min="0" value="0" required>
                    </div>
                    <div class="row">
                        <label for="requirements">Requirements</label>
                        <textarea id="requirements" name="requirements" placeholder="List prerequisites or requirements for this course"></textarea>
                    </div>
                    <div class="check-row">
                        <label><input type="checkbox" name="is_live_available" value="1" checked> Live mode available</label>
                        <label><input type="checkbox" name="is_prerecorded_available" value="1" checked> Pre-recorded available</label>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">
                            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            Create course
                        </button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Existing courses</div>
                </div>
                @if ($coursesList->isEmpty())
                    <div class="panel-empty">No courses created yet.</div>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Course</th>
                                    <th class="num">Price</th>
                                    <th class="num">Slots</th>
                                    <th class="num">Students</th>
                                    <th class="num">Classrooms</th>
                                    <th class="num">Tasks</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($coursesList as $course)
                                    <tr>
                                        <td>
                                            <strong>{{ $course['title'] }}</strong>
                                            <div class="row-sub">{{ $course['description'] ?: 'No description yet.' }}</div>
                                        </td>
                                        <td class="num">₦{{ number_format($course['price'], 0) }}</td>
                                        <td class="num">{{ $course['registered_count'] }}{{ $course['max_students'] > 0 ? '/' . $course['max_students'] : '/∞' }}</td>
                                        <td class="num">{{ $course['students'] }}</td>
                                        <td class="num">{{ $course['classrooms'] }}</td>
                                        <td class="num">{{ $course['tasks'] }}</td>
                                        <td>
                                            <span class="course-state {{ $course['is_active'] ? 'active' : 'inactive' }}">{{ $course['is_active'] ? 'Active' : 'Inactive' }}</span>
                                        </td>
                                        <td>
                                            <form method="POST" action="{{ route('lms.courses.delete', $course['id']) }}" onsubmit="return confirm('Delete this course?');">
                                                @csrf
                                                <button type="submit" class="del">
                                                    <svg viewBox="0 0 24 24"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="m19 6-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/></svg>
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        </div>
    </div>
@endsection
