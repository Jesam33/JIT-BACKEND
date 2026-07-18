@extends('admin.lms.layout')

@section('title', 'LMS Classrooms')
@php($activeLmsPage = 'classrooms')

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(360px, 480px) minmax(0, 1fr); gap: 18px; }
        .card { padding: 18px; }
        .row { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #4a5f72; }
        input, select { width: 100%; border: 1px solid #d7dfe8; border-radius: 10px; padding: 11px 12px; font: inherit; }
        input:focus, select:focus { border-color: #c1121f; outline: none; box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.10); }
        .actions { display: flex; gap: 10px; }
        .btn { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn-danger { background: #8a1515; color: #fff; border: 0; border-radius: 8px; padding: 9px 12px; font-weight: 700; cursor: pointer; }
        .ok { border-radius: 10px; padding: 12px 14px; font-size: 14px; background: var(--success-soft); color: var(--success); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); font-size: 14px; vertical-align: top; }
        th { background: #fbfdff; color: #627a8f; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .stack { display: grid; gap: 14px; }
        .link-cell a { color: var(--accent); }
        @media (max-width: 1080px) { .grid { grid-template-columns: 1fr; } }
    </style>
@endsection

@section('toolbar_title', 'Classroom Delivery')
@section('toolbar_text', 'Create the live classroom sessions staff will run, including course ownership, instructor assignment, meeting links, and timing windows.')

@section('toolbar_actions')
    <div class="chip">Classrooms <strong>{{ $classroomsList->count() }}</strong></div>
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
                    <div class="panel-title">Create classroom</div>
                </div>
                <form method="POST" action="{{ route('lms.classrooms.store') }}">
                    @csrf
                    <div class="row">
                        <label for="course_id">Course</label>
                        <select id="course_id" name="course_id" required>
                            <option value="">Select course</option>
                            @foreach ($coursesList as $course)
                                <option value="{{ $course->id }}">{{ $course->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <label for="teacher_id">Instructor</label>
                        <select id="teacher_id" name="teacher_id">
                            <option value="">Optional</option>
                            @foreach ($teachersList as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <label for="title">Session title</label>
                        <input id="title" name="title" type="text" required>
                    </div>
                    <div class="row">
                        <label for="meeting_url">Meeting URL</label>
                        <input id="meeting_url" name="meeting_url" type="url" required>
                    </div>
                    <div class="row">
                        <label for="starts_at">Starts at</label>
                        <input id="starts_at" name="starts_at" type="datetime-local" required>
                    </div>
                    <div class="row">
                        <label for="ends_at">Ends at</label>
                        <input id="ends_at" name="ends_at" type="datetime-local">
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">Create classroom</button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Scheduled classrooms</div>
                </div>
                @if ($classroomsList->isEmpty())
                    <div class="panel-empty">No classrooms created yet.</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Course</th>
                                <th>Instructor</th>
                                <th>Schedule</th>
                                <th>Meeting</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($classroomsList as $classroom)
                                <tr>
                                    <td>{{ $classroom['title'] }}</td>
                                    <td>{{ $classroom['course_title'] ?: '-' }}</td>
                                    <td>{{ $classroom['teacher_name'] ?: '-' }}</td>
                                    <td>
                                        {{ optional($classroom['starts_at'])->format('Y-m-d H:i') }}
                                        <div class="small">{{ $classroom['ends_at'] ? 'Ends '.optional($classroom['ends_at'])->format('Y-m-d H:i') : 'No end time set' }}</div>
                                    </td>
                                    <td class="link-cell"><a href="{{ $classroom['meeting_url'] }}" target="_blank" rel="noreferrer">Open link</a></td>
                                    <td>
                                        <form method="POST" action="{{ route('lms.classrooms.delete', $classroom['id']) }}" onsubmit="return confirm('Delete this classroom?');">
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
