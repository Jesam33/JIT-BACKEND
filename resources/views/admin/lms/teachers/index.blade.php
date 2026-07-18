@extends('admin.lms.layout')

@section('title', 'LMS Staff Accounts')
@php($activeLmsPage = 'teachers')

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 18px; }
        .card { padding: 18px; }
        .row { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #4a5f72; }
        input { width: 100%; border: 1px solid #d7dfe8; border-radius: 10px; padding: 11px 12px; font: inherit; }
        input:focus { border-color: #c1121f; outline: none; box-shadow: 0 0 0 3px rgba(193, 18, 31, 0.10); }
        .actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .btn { background: var(--accent); color: #fff; border: 0; border-radius: 8px; padding: 10px 14px; font-weight: 700; cursor: pointer; }
        .btn-secondary { border: 1px solid var(--line); background: #fff; color: var(--ink); border-radius: 8px; padding: 10px 14px; text-decoration: none; }
        .ok, .note { border-radius: 10px; padding: 12px 14px; font-size: 14px; }
        .ok { background: var(--success-soft); color: var(--success); }
        .note { background: #f5f8fb; color: #385066; border: 1px solid var(--line); }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 12px 10px; border-bottom: 1px solid var(--line); font-size: 14px; }
        th { background: #fbfdff; color: #627a8f; font-size: 11px; text-transform: uppercase; letter-spacing: 0.08em; }
        .stack { display: grid; gap: 14px; }
        @media (max-width: 1080px) { .grid { grid-template-columns: 1fr; } }
    </style>
@endsection

@section('toolbar_title', 'Staff Provisioning')
@section('toolbar_text', 'Create staff accounts for instructors and internal LMS operators, then hand over credentials for teaching, grading, and classroom delivery.')

@section('toolbar_actions')
    <div class="chip">Staff <strong>{{ $teachersList->count() }}</strong></div>
    <a href="{{ route('admin.lms.index') }}" class="action">Back to dashboard</a>
@endsection

@section('content')
    <div class="stack">
        @if (session('status'))
            <div class="ok">{{ session('status') }}</div>
        @endif

        @if (session('created_teacher_email') && session('created_teacher_password'))
            <div class="note">
                Staff credentials created: <strong>{{ session('created_teacher_email') }}</strong> / <strong>{{ session('created_teacher_password') }}</strong>
            </div>
        @endif

        <div class="grid">
            <section class="panel card">
                <div class="panel-head" style="margin: -18px -18px 18px;">
                    <div class="panel-title">Create staff account</div>
                </div>
                <form method="POST" action="{{ route('lms.teachers.store') }}">
                    @csrf
                    <div class="row">
                        <label for="name">Full name</label>
                        <input id="name" name="name" type="text" required>
                    </div>
                    <div class="row">
                        <label for="email">Email</label>
                        <input id="email" name="email" type="email" required>
                    </div>
                    <div class="actions">
                        <button type="submit" class="btn">Create staff</button>
                        <a href="{{ route('admin.lms.index') }}" class="btn-secondary">Cancel</a>
                    </div>
                </form>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Current staff accounts</div>
                </div>
                @if ($teachersList->isEmpty())
                    <div class="panel-empty">No staff accounts exist yet.</div>
                @else
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($teachersList as $teacher)
                                <tr>
                                    <td>{{ $teacher->name }}</td>
                                    <td>{{ $teacher->email }}</td>
                                    <td>
                                        <span class="course-state {{ $teacher->is_active ? 'active' : 'inactive' }}">{{ $teacher->is_active ? 'Active' : 'Inactive' }}</span>
                                    </td>
                                    <td>{{ optional($teacher->created_at)->format('Y-m-d') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>
    </div>
@endsection
