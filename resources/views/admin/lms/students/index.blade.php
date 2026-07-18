@php($activeLmsPage = 'students')
@extends('admin.lms.layout')

@section('title', 'All Students — LMS Admin')
@section('toolbar_title', 'All Students')
@section('toolbar_text', 'View all registered learners, filter by course or track, search by name or email, and manage student accounts.')

@section('head')
<style>
    /* ── Filter bar ─────────────────────────────────────────── */
    .filter-bar {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        padding: 14px 18px;
        border-bottom: 1px solid var(--line);
        background: var(--panel-alt);
    }

    .filter-bar form {
        display: contents;
    }

    .search-wrap {
        position: relative;
        flex: 1 1 200px;
        min-width: 180px;
    }

    .search-wrap svg {
        position: absolute;
        left: 10px;
        top: 50%;
        transform: translateY(-50%);
        width: 15px;
        height: 15px;
        color: var(--muted);
        pointer-events: none;
    }

    .search-wrap input[type="search"],
    .search-wrap input[type="text"] {
        width: 100%;
        padding: 9px 10px 9px 33px;
        border: 1px solid var(--line);
        border-radius: 8px;
        font-size: 13px;
        font-family: inherit;
        color: var(--ink);
        background: #fff;
        outline: none;
        transition: border-color .18s;
    }

    .search-wrap input:focus {
        border-color: var(--accent);
    }

    .filter-select {
        padding: 9px 32px 9px 10px;
        border: 1px solid var(--line);
        border-radius: 8px;
        font-size: 13px;
        font-family: inherit;
        color: var(--ink);
        background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%23728194' stroke-width='1.5' fill='none' stroke-linecap='round'/%3E%3C/svg%3E") no-repeat right 10px center;
        appearance: none;
        cursor: pointer;
        outline: none;
        transition: border-color .18s;
        flex: 0 0 auto;
    }

    .filter-select:focus {
        border-color: var(--accent);
    }

    .filter-apply {
        padding: 9px 16px;
        background: var(--accent);
        color: #fff;
        border: none;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: background .18s;
        flex: 0 0 auto;
    }

    .filter-apply:hover {
        background: var(--accent-dark);
    }

    .filter-clear {
        padding: 9px 14px;
        border: 1px solid var(--line);
        background: #fff;
        border-radius: 8px;
        font-size: 13px;
        font-family: inherit;
        color: var(--muted);
        text-decoration: none;
        cursor: pointer;
        transition: border-color .18s, color .18s;
        flex: 0 0 auto;
    }

    .filter-clear:hover {
        border-color: var(--accent);
        color: var(--accent);
    }

    /* ── Active filter chips ────────────────────────────────── */
    .active-filters {
        display: flex;
        align-items: center;
        gap: 6px;
        padding: 8px 18px;
        border-bottom: 1px solid var(--line);
        background: #fff;
        flex-wrap: wrap;
    }

    .af-label {
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 700;
        color: var(--muted);
        margin-right: 4px;
    }

    .af-chip {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        background: var(--accent-soft);
        color: var(--accent);
        border-radius: 999px;
        padding: 3px 8px 3px 10px;
        font-size: 12px;
        font-weight: 600;
        text-decoration: none;
    }

    .af-chip span {
        line-height: 1;
        font-size: 14px;
        margin-top: -1px;
    }

    /* ── Panel header ───────────────────────────────────────── */
    .panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 14px 18px;
        border-bottom: 1px solid var(--line);
        background: var(--panel-alt);
    }

    .panel-title {
        color: #4f6478;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 700;
    }

    .panel-meta {
        font-size: 12px;
        color: var(--muted);
    }

    /* ── Table ──────────────────────────────────────────────── */
    .students-table {
        width: 100%;
        border-collapse: collapse;
    }

    .students-table th,
    .students-table td {
        text-align: left;
        padding: 11px 14px;
        border-bottom: 1px solid var(--line);
        font-size: 13px;
    }

    .students-table th {
        color: #627a8f;
        font-size: 11px;
        text-transform: uppercase;
        letter-spacing: .08em;
        font-weight: 800;
        background: #fbfdff;
        white-space: nowrap;
    }

    .students-table tbody tr:hover {
        background: #f7fafc;
    }

    .students-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* ── Student avatar ─────────────────────────────────────── */
    .student-cell {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .student-avatar {
        width: 34px;
        height: 34px;
        border-radius: 10px;
        background: var(--sidebar);
        color: rgba(255,255,255,0.92);
        font-size: 13px;
        font-weight: 700;
        display: grid;
        place-items: center;
        flex-shrink: 0;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }

    .student-name {
        font-weight: 600;
        color: var(--ink);
        font-size: 13px;
    }

    .student-email {
        font-size: 11px;
        color: var(--muted);
        margin-top: 1px;
    }

    /* ── Badges ─────────────────────────────────────────────── */
    .badge {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 3px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }

    .badge-success {
        background: var(--success-soft);
        color: var(--success);
    }

    .badge-muted {
        background: #eef2f6;
        color: #7a8c9c;
    }

    /* ── Mode pill ──────────────────────────────────────────── */
    .mode-pill {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 600;
        background: var(--accent-soft);
        color: var(--accent);
    }

    /* ── CTA actions cell ───────────────────────────────────── */
    .row-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .btn-delete {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 7px;
        font-size: 12px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        border: 1px solid transparent;
        transition: background .18s, color .18s, border-color .18s;
        text-decoration: none;
        background: var(--danger-soft);
        color: var(--danger);
        border-color: transparent;
    }

    .btn-delete:hover {
        background: var(--danger);
        color: #fff;
    }

    /* ── Empty state ────────────────────────────────────────── */
    .empty-row td {
        padding: 48px 24px;
        text-align: center;
        color: var(--muted);
        font-size: 14px;
    }

    .empty-icon {
        font-size: 32px;
        margin-bottom: 10px;
        opacity: .5;
    }

    /* ── Pagination ─────────────────────────────────────────── */
    .panel-footer {
        padding: 12px 18px;
        border-top: 1px solid var(--line);
        background: var(--panel-alt);
    }

    /* ── Confirm modal ──────────────────────────────────────── */
    .spin {
        animation: spinAnim .8s linear infinite;
    }

    @keyframes spinAnim {
        to { transform: rotate(360deg); }
    }

    .modal-backdrop {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(15, 23, 36, .55);
        z-index: 900;
        place-items: center;
    }

    .modal-backdrop.open {
        display: grid;
    }

    .modal {
        background: #fff;
        border-radius: 16px;
        box-shadow: 0 24px 64px rgba(15, 23, 36, .22);
        padding: 28px;
        width: 100%;
        max-width: 400px;
        margin: 16px;
    }

    .modal-icon {
        width: 46px;
        height: 46px;
        border-radius: 12px;
        background: var(--danger-soft);
        display: grid;
        place-items: center;
        margin-bottom: 14px;
    }

    .modal-icon svg {
        width: 22px;
        height: 22px;
        color: var(--danger);
    }

    .modal h2 {
        margin: 0 0 8px;
        font-size: 18px;
        letter-spacing: -.02em;
    }

    .modal p {
        margin: 0 0 22px;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.6;
    }

    .modal p strong {
        color: var(--ink);
    }

    .modal-actions {
        display: flex;
        gap: 8px;
        justify-content: flex-end;
    }

    .btn-cancel {
        padding: 9px 18px;
        border: 1px solid var(--line);
        border-radius: 8px;
        background: #fff;
        font-size: 13px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        color: var(--muted);
        transition: border-color .18s;
    }

    .btn-cancel:hover {
        border-color: #a0aec0;
        color: var(--ink);
    }

    .btn-confirm-delete {
        padding: 9px 18px;
        border: none;
        border-radius: 8px;
        background: var(--danger);
        color: #fff;
        font-size: 13px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: background .18s;
    }

    .btn-confirm-delete:hover {
        background: #991b1b;
    }

    /* ── Flash banner ───────────────────────────────────────── */
    .flash-banner {
        margin: 0;
        padding: 12px 18px;
        background: var(--success-soft);
        color: var(--success);
        font-size: 13px;
        font-weight: 600;
        border-bottom: 1px solid rgba(31,157,85,.18);
        display: flex;
        align-items: center;
        gap: 8px;
    }

    @media (max-width: 860px) {
        .students-table th:nth-child(3),
        .students-table td:nth-child(3),
        .students-table th:nth-child(5),
        .students-table td:nth-child(5) {
            display: none;
        }
    }

    @media (max-width: 640px) {
        .filter-bar {
            flex-direction: column;
            align-items: stretch;
        }
        .search-wrap { flex: 1 1 100%; }
    }
</style>
@endsection

@section('toolbar_actions')
    <div class="chip">Total <strong>{{ $students->total() }}</strong></div>
    @if(request()->hasAny(['search','course_id','track_id']))
        <a href="{{ route('admin.lms.students.index') }}" class="action">Clear filters</a>
    @endif
@endsection

@section('content')
<div class="panel">

    {{-- Flash status --}}
    @if(session('status'))
        <div class="flash-banner">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ session('status') }}
        </div>
    @endif

    {{-- ── Filter bar ──────────────────────────────────────── --}}
    <div class="filter-bar">
        <form method="GET" action="{{ route('admin.lms.students.index') }}" style="display:contents">
            {{-- Search --}}
            <div class="search-wrap">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11A6 6 0 1 1 5 11a6 6 0 0 1 12 0z"/></svg>
                <input
                    type="search"
                    name="search"
                    placeholder="Search by name or email…"
                    value="{{ request('search') }}"
                    autocomplete="off"
                >
            </div>

            {{-- Course filter --}}
            <select name="course_id" class="filter-select">
                <option value="">All Courses</option>
                @foreach($coursesList as $course)
                    <option value="{{ $course->id }}" {{ request('course_id') == $course->id ? 'selected' : '' }}>
                        {{ $course->title }}
                    </option>
                @endforeach
            </select>

            {{-- Track filter --}}
            <select name="track_id" class="filter-select">
                <option value="">All Tracks</option>
                @foreach($tracksList as $track)
                    <option value="{{ $track->id }}" {{ request('track_id') == $track->id ? 'selected' : '' }}>
                        {{ $track->name }}
                    </option>
                @endforeach
            </select>

            <button type="submit" class="filter-apply">Apply Filters</button>

            @if(request()->hasAny(['search','course_id','track_id']))
                <a href="{{ route('admin.lms.students.index') }}" class="filter-clear">✕ Clear</a>
            @endif
        </form>
    </div>

    {{-- ── Active filter chips ─────────────────────────────── --}}
    @if(request()->hasAny(['search','course_id','track_id']))
    <div class="active-filters">
        <span class="af-label">Filtered by:</span>
        @if(request('search'))
            <a class="af-chip" href="{{ route('admin.lms.students.index', request()->except('search')) }}">
                Search: "{{ request('search') }}" <span>×</span>
            </a>
        @endif
        @if(request('course_id'))
            @php($activeCourse = $coursesList->firstWhere('id', request('course_id')))
            <a class="af-chip" href="{{ route('admin.lms.students.index', request()->except('course_id')) }}">
                Course: {{ $activeCourse?->title ?? 'Unknown' }} <span>×</span>
            </a>
        @endif
        @if(request('track_id'))
            @php($activeTrack = $tracksList->firstWhere('id', request('track_id')))
            <a class="af-chip" href="{{ route('admin.lms.students.index', request()->except('track_id')) }}">
                Track: {{ $activeTrack?->name ?? 'Unknown' }} <span>×</span>
            </a>
        @endif
    </div>
    @endif

    {{-- ── Panel header ─────────────────────────────────────── --}}
    <div class="panel-header">
        <div class="panel-title">
            Students &nbsp;<strong style="font-size:14px;font-weight:800;color:var(--ink)">{{ $students->total() }}</strong>
        </div>
        <div class="panel-meta">
            Page {{ $students->currentPage() }} of {{ $students->lastPage() }}
        </div>
    </div>

    {{-- ── Table ────────────────────────────────────────────── --}}
    <div style="overflow-x:auto">
        <table class="students-table">
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Course</th>
                    <th>Track Enrollments</th>
                    <th>Mode</th>
                    <th>Onboarded</th>
                    <th>Registered</th>
                    <th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($students as $student)
                    <tr>
                        {{-- Student name + email --}}
                        <td>
                            <div class="student-cell">
                                <div class="student-avatar">
                                    {{ strtoupper(substr($student->first_name ?? $student->email, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="student-name">{{ $student->name ?? ($student->first_name . ' ' . $student->last_name) }}</div>
                                    <div class="student-email">{{ $student->email }}</div>
                                </div>
                            </div>
                        </td>

                        {{-- Course --}}
                        <td>{{ $student->course?->title ?? '—' }}</td>

                        {{-- Track enrollments count --}}
                        <td>
                            @if($student->enrollments_count > 0)
                                <span class="badge badge-success">{{ $student->enrollments_count }} track{{ $student->enrollments_count !== 1 ? 's' : '' }}</span>
                            @else
                                <span style="color:var(--muted);font-size:12px">None</span>
                            @endif
                        </td>

                        {{-- Learning mode --}}
                        <td>
                            @if($student->learning_mode)
                                <span class="mode-pill">{{ ucfirst($student->learning_mode) }}</span>
                            @else
                                <span style="color:var(--muted);font-size:12px">—</span>
                            @endif
                        </td>

                        {{-- Onboarded --}}
                        <td>
                            @if($student->onboarding_completed)
                                <span class="badge badge-success">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="11" height="11" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    Yes
                                </span>
                            @else
                                <span class="badge badge-muted">No</span>
                            @endif
                        </td>

                        {{-- Registered date --}}
                        <td style="white-space:nowrap;color:var(--muted);font-size:12px">
                            {{ $student->created_at->format('Y-m-d') }}
                        </td>

                        {{-- Actions --}}
                        <td>
                            <div class="row-actions" style="justify-content:flex-end">
                                <button
                                    type="button"
                                    class="btn-delete"
                                    onclick="openDeleteModal({{ $student->id }}, '{{ addslashes($student->first_name . ' ' . $student->last_name) }}', '{{ addslashes($student->email) }}')"
                                    title="Delete student"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" width="13" height="13" fill="none" viewBox="0 0 24 24"><path stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg>
                                    Delete
                                </button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr class="empty-row">
                        <td colspan="7">
                            <div class="empty-icon">◎</div>
                            @if(request()->hasAny(['search','course_id','track_id']))
                                No students match the current filters.
                            @else
                                No students registered yet.
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($students->hasPages())
        <div class="panel-footer">
            {{ $students->links() }}
        </div>
    @endif
</div>

{{-- ── Delete confirmation modal ─────────────────────────────── --}}
<div class="modal-backdrop" id="deleteModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title">
        <div class="modal-icon">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
        </div>
        <h2 id="modal-title">Delete Student?</h2>
        <p id="modal-body">
            You are about to permanently delete this student account. All associated enrollments, submissions, and session data will also be removed. This action <strong>cannot be undone</strong>.
        </p>
        <form id="deleteForm" method="POST" onsubmit="return handleDelete(event)">
            @csrf
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeDeleteModal()">Cancel</button>
                <button type="submit" class="btn-confirm-delete" id="confirmDeleteBtn">
                    <span id="deleteBtnText">Yes, Delete Student</span>
                    <span id="deleteBtnSpinner" style="display:none">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="spin"><path d="M12 2v4M12 18v4M4.93 4.93l2.83 2.83M16.24 16.24l2.83 2.83M2 12h4M18 12h4M4.93 19.07l2.83-2.83M16.24 7.76l2.83-2.83"/></svg>
                        Deleting…
                    </span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openDeleteModal(id, name, email) {
        document.getElementById('deleteForm').action = '{{ url(trim(env("ADMIN_DIR","admin"), "/") . "/lms/students") }}/' + id + '/delete';
        document.getElementById('modal-body').innerHTML =
            'You are about to permanently delete <strong>' + escHtml(name) + '</strong> (' + escHtml(email) + '). '
            + 'All associated enrollments, submissions, and session data will also be removed. This action <strong>cannot be undone</strong>.';
        document.getElementById('deleteModal').classList.add('open');
        document.body.style.overflow = 'hidden';
    }

    function closeDeleteModal() {
        document.getElementById('deleteModal').classList.remove('open');
        document.body.style.overflow = '';
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.appendChild(document.createTextNode(str));
        return d.innerHTML;
    }

    function handleDelete(e) {
        var btn = document.getElementById('confirmDeleteBtn');
        if (btn.disabled) return false;
        btn.disabled = true;
        document.getElementById('deleteBtnText').style.display = 'none';
        document.getElementById('deleteBtnSpinner').style.display = 'inline';
        return true;
    }

    // Close on backdrop click
    document.getElementById('deleteModal').addEventListener('click', function (e) {
        if (e.target === this) closeDeleteModal();
    });

    // Close on Escape
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') closeDeleteModal();
    });
</script>
@endsection
