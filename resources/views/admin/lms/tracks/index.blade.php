@extends('admin.lms.layout')

@section('title', 'LMS Tracks')
@php ($activeLmsPage = 'tracks') @endphp

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(340px, 460px) minmax(0, 1fr); gap: 18px; }
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
        @media (max-width: 1080px) { .grid { grid-template-columns: 1fr; } }
    </style>
@endsection

@section('toolbar_title', 'Tracks & Cohorts')
@section('toolbar_text', 'Structure learners into tracks tied to courses, instructors, and optional batch windows so delivery and communication stay organized.')

@section('toolbar_actions')
    <div class="chip">Tracks <strong>{{ $tracksList->count() }}</strong></div>
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
                    <div class="panel-title">Create track</div>
                </div>
                <form method="POST" action="{{ route('lms.tracks.store') }}" class="panel-body">
                    @csrf
                    <div class="row">
                        <label for="name">Track name</label>
                        <input id="name" name="name" type="text" required>
                    </div>
                    <div class="row">
                        <label for="instructor_id">Instructor</label>
                        <select id="instructor_id" name="instructor_id" required>
                            <option value="">Select instructor</option>
                            @foreach ($teachersList as $teacher)
                                <option value="{{ $teacher->id }}">{{ $teacher->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <label for="course_id">Course</label>
                        <select id="course_id" name="course_id">
                            <option value="">Optional</option>
                            @foreach ($coursesList as $course)
                                <option value="{{ $course->id }}">{{ $course->title }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="row">
                        <label for="batch_id">Batch</label>
                        <select id="batch_id" name="batch_id">
                            <option value="">Optional</option>
                            @foreach ($batchesList as $batch)
                                <option value="{{ $batch->id }}">{{ $batch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">
                            <svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                            Create track
                        </button>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Existing tracks</div>
                </div>
                @if ($tracksList->isEmpty())
                    <div class="panel-empty">No tracks created yet.</div>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Instructor</th>
                                    <th>Course</th>
                                    <th>Batch</th>
                                    <th class="num">Created</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($tracksList as $track)
                                    <tr>
                                        <td><strong>{{ $track['name'] }}</strong></td>
                                        <td>{{ $track['teacher_name'] ?: 'None' }}</td>
                                        <td>{{ $track['course_title'] ?: 'None' }}</td>
                                        <td>{{ $track['batch_name'] ?: 'None' }}</td>
                                        <td class="num">{{ optional($track['created_at'])->format('Y-m-d') }}</td>
                                        <td>
                                            <form method="POST" action="{{ route('lms.tracks.delete', $track['id']) }}" onsubmit="return confirm('Delete this track?');">
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
