@extends('admin.lms.layout')

@section('title', 'LMS Classrooms')
@php ($activeLmsPage = 'classrooms') @endphp

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(360px, 480px) minmax(0, 1fr); gap: 18px; }
        .row { margin-bottom: 14px; }
        .row:last-child { margin-bottom: 0; }
        label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #4f6478; }
        input, select {
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
        input:hover, select:hover { border-color: #cfd8e2; }
        input:focus, select:focus {
            border-color: var(--accent);
            outline: none;
            box-shadow: 0 0 0 3px var(--accent-soft);
        }
        .actions { display: flex; gap: 10px; }
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
        .btn svg, .del svg, .link-cell svg {
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
        .link-cell a {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            color: var(--accent);
            font-weight: 700;
            text-decoration: none;
        }
        .link-cell a:hover { color: var(--accent-dark); }
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
            <div class="ok">
                <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                {{ session('status') }}
            </div>
        @endif

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Create classroom</div>
                </div>
                <form method="POST" action="{{ route('lms.classrooms.store') }}" class="panel-body">
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
                        <button type="submit" class="btn">
                            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            Create classroom
                        </button>
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
                    <div class="table-wrap">
                        <table class="data-table">
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
                                        <td><strong>{{ $classroom['title'] }}</strong></td>
                                        <td>{{ $classroom['course_title'] ?: 'None' }}</td>
                                        <td>{{ $classroom['teacher_name'] ?: 'None' }}</td>
                                        <td>
                                            {{ optional($classroom['starts_at'])->format('Y-m-d H:i') }}
                                            <div class="row-sub">{{ $classroom['ends_at'] ? 'Ends '.optional($classroom['ends_at'])->format('Y-m-d H:i') : 'No end time set' }}</div>
                                        </td>
                                        <td class="link-cell">
                                            @if ($classroom['meeting_url'])
                                                <a href="{{ $classroom['meeting_url'] }}" target="_blank" rel="noreferrer">
                                                    <svg viewBox="0 0 24 24"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/></svg>
                                                    Open link
                                                </a>
                                            @else
                                                <span class="row-sub">{{ $classroom['meeting_id'] ?: 'Not set' }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <form method="POST" action="{{ route('lms.classrooms.delete', $classroom['id']) }}" onsubmit="return confirm('Delete this classroom?');">
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
