@extends('admin.lms.layout')

@section('title', 'LMS Tracks')
@php($activeLmsPage = 'tracks')

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(340px, 460px) minmax(0, 1fr); gap: 18px; }
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
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { background: #fbfdff; color: #627a8f; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .stack { display: grid; gap: 14px; }
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
            <div class="ok">{{ session('status') }}</div>
        @endif

        <div class="grid">
            <section class="panel card">
                <div class="panel-head" style="margin: -18px -18px 18px;">
                    <div class="panel-title">Create track</div>
                </div>
                <form method="POST" action="{{ route('lms.tracks.store') }}">
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
                        <button type="submit" class="btn">Create track</button>
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
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Instructor</th>
                                <th>Course</th>
                                <th>Batch</th>
                                <th>Created</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tracksList as $track)
                                <tr>
                                    <td>{{ $track['name'] }}</td>
                                    <td>{{ $track['teacher_name'] ?: '-' }}</td>
                                    <td>{{ $track['course_title'] ?: '-' }}</td>
                                    <td>{{ $track['batch_name'] ?: '-' }}</td>
                                    <td>{{ optional($track['created_at'])->format('Y-m-d') }}</td>
                                    <td>
                                        <form method="POST" action="{{ route('lms.tracks.delete', $track['id']) }}" onsubmit="return confirm('Delete this track?');">
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
