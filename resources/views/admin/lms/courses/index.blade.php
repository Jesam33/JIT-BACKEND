@extends('admin.lms.layout')

@section('title', 'LMS Courses')
@php($activeLmsPage = 'courses')

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 18px; }
        .card { padding: 18px; }
        .row { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #4a5f72; }
        input, textarea, select { width: 100%; border: 1px solid #d7dfe8; border-radius: 10px; padding: 11px 12px; font: inherit; }
        textarea { min-height: 120px; resize: vertical; }
        input:focus, textarea:focus, select:focus { border-color: #c1121f; outline: none; box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.10); }
        .check-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 16px; }
        .check-row label { display: inline-flex; gap: 8px; align-items: center; margin: 0; font-weight: 600; }
        .check-row input { width: auto; }
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn-danger { background: #8a1515; color: #fff; border: 0; border-radius: 8px; padding: 9px 12px; font-weight: 700; cursor: pointer; }
        .ok { border-radius: 10px; padding: 12px 14px; font-size: 14px; background: var(--success-soft); color: var(--success); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: top; }
        th { background: #fbfdff; color: #627a8f; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .stack { display: grid; gap: 14px; }
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
            <div class="ok">{{ session('status') }}</div>
        @endif

        <div class="grid">
            <section class="panel card">
                <div class="panel-head" style="margin: -18px -18px 18px;">
                    <div class="panel-title">Create course</div>
                </div>
                <form method="POST" action="{{ route('lms.courses.store') }}">
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
                        <button type="submit" class="btn">Create course</button>
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
                    <table>
                        <thead>
                            <tr>
                                <th>Course</th>
                                <th>Price</th>
                                <th>Slots</th>
                                <th>Students</th>
                                <th>Classrooms</th>
                                <th>Tasks</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($coursesList as $course)
                                <tr>
                                    <td>
                                        <strong>{{ $course['title'] }}</strong>
                                        <div class="small">{{ $course['description'] ?: 'No description yet.' }}</div>
                                    </td>
                                    <td>₦{{ number_format($course['price'], 0) }}</td>
                                    <td>{{ $course['registered_count'] }}{{ $course['max_students'] > 0 ? '/' . $course['max_students'] : '/∞' }}</td>
                                    <td>{{ $course['students'] }}</td>
                                    <td>{{ $course['classrooms'] }}</td>
                                    <td>{{ $course['tasks'] }}</td>
                                    <td>
                                        <span class="course-state {{ $course['is_active'] ? 'active' : 'inactive' }}">{{ $course['is_active'] ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td>
                                        <form method="POST" action="{{ route('lms.courses.delete', $course['id']) }}" onsubmit="return confirm('Delete this course?');">
                                            @csrf
                                            <button type="submit" class="btn-danger">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
@endsection
