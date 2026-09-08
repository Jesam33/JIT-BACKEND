@extends('admin.lms.layout')

@section('title', 'LMS Staff Accounts')
@php ($activeLmsPage = 'teachers') @endphp

@section('head')
    <style>
        .grid { display: grid; grid-template-columns: minmax(320px, 420px) minmax(0, 1fr); gap: 18px; align-items: start; }
        .stack { display: grid; gap: 18px; }
        .field { margin-bottom: 14px; }
        .field label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #4f6478; }
        .field input { width: 100%; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 13px; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .field input:hover { border-color: #cfd8e2; }
        .field input:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-soft); }
        .form-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
        .form-actions .action { cursor: pointer; }
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-note { border-radius: 10px; padding: 12px 14px; font-size: 13px; background: var(--panel-alt); color: #385066; border: 1px solid var(--line); }
        .avatar-tile { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; background: var(--accent-soft); color: var(--accent-dark); font-size: 13px; font-weight: 800; letter-spacing: -0.01em; flex: none; }
        .staff-cell { display: flex; align-items: center; gap: 10px; }
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
            <div class="flash-ok">{{ session('status') }}</div>
        @endif

        @if (session('created_teacher_email') && session('created_teacher_password'))
            <div class="flash-note">
                Staff credentials created: <strong>{{ session('created_teacher_email') }}</strong> / <strong>{{ session('created_teacher_password') }}</strong>
            </div>
        @endif

        <div class="grid">
            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Create staff account</div>
                </div>
                <div class="panel-body">
                    <form method="POST" action="{{ route('lms.teachers.store') }}">
                        @csrf
                        <div class="field">
                            <label for="name">Full name</label>
                            <input id="name" name="name" type="text" required>
                        </div>
                        <div class="field">
                            <label for="email">Email</label>
                            <input id="email" name="email" type="email" required>
                        </div>
                        <div class="form-actions">
                            <button type="submit" class="action primary">Create staff</button>
                            <a href="{{ route('admin.lms.index') }}" class="action">Cancel</a>
                        </div>
                    </form>
                </div>
            </section>

            <section class="panel">
                <div class="panel-head">
                    <div class="panel-title">Current staff accounts</div>
                </div>
                @if ($teachersList->isEmpty())
                    <div class="panel-empty">No staff accounts exist yet.</div>
                @else
                    <div class="table-wrap">
                        <table class="data-table">
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
                                        <td>
                                            <div class="staff-cell">
                                                <span class="avatar-tile">{{ strtoupper(substr($teacher->name, 0, 1)) }}</span>
                                                <strong>{{ $teacher->name }}</strong>
                                            </div>
                                        </td>
                                        <td>{{ $teacher->email }}</td>
                                        <td>
                                            <span class="course-state {{ $teacher->is_active ? 'active' : 'inactive' }}">{{ $teacher->is_active ? 'Active' : 'Inactive' }}</span>
                                        </td>
                                        <td class="num">{{ optional($teacher->created_at)->format('Y-m-d') }}</td>
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
