@extends('admin.lms.layout')

@section('title', 'Feedback')
@php ($activeLmsPage = 'feedback') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); }
        .stack { display: grid; gap: 18px; }

        .fb-row { padding: 16px 18px; border-bottom: 1px solid var(--line); }
        .fb-row:last-child { border-bottom: 0; }
        .fb-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
        .fb-category { font-weight: 700; color: var(--ink); font-size: 13px; }
        .fb-age { margin-left: auto; font-size: 12px; color: var(--muted); }
        .fb-message { font-size: 13px; color: #33465a; line-height: 1.55; white-space: pre-wrap; margin: 0 0 10px; }
        .fb-who { font-size: 12px; color: var(--muted); }
        .fb-who strong { color: #33465a; }
        .fb-page { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; background: var(--surface-2, #f3f5f8); border-radius: 6px; padding: 2px 6px; color: #33465a; }

        .triage-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; margin-top: 10px; }
        .triage-form textarea { flex: 1 1 260px; min-width: 220px; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 9px 11px; font: inherit; font-size: 12px; resize: vertical; }
        .triage-form textarea:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-soft); }
        .triage-form .action { cursor: pointer; }
        .note-shown { font-size: 12px; color: var(--muted); margin-top: 8px; font-style: italic; }
        .triage-hint { font-size: 12px; color: var(--muted); flex-basis: 100%; }
    </style>
@endsection

@section('toolbar_title', 'Feedback')
@section('toolbar_text', 'What the people using the academies\' portals are telling us. Nothing here is emailed back — the note is a record of our decision.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('content')
    <div class="stack">
        @if(session('status'))
            <div class="flash-ok">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="flash-error">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="flash-error">
                <ul style="margin:0;padding-left:18px">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(($showSettled ?? false))
            <div class="panel">
                <div class="panel-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="font-size:13px;color:var(--muted)">Showing planned, done and dismissed feedback too.</span>
                    <a href="{{ route('admin.lms.feedback.index') }}" class="action">Show new only</a>
                </div>
            </div>
        @endif

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">Newest first</div>
            </div>

            @if(count($feedback ?? []) === 0)
                <div class="panel-empty">Nothing waiting.</div>
            @else
                @foreach($feedback as $f)
                    <div class="fb-row">
                        <div class="fb-top">
                            <span class="fb-category">{{ $f['category_label'] }}</span>
                            <span class="course-state {{ $f['status_class'] }}">{{ ucfirst($f['status']) }}</span>
                            <span class="fb-age">{{ $f['age'] }}</span>
                        </div>

                        {{-- Escaped by Blade. This is the sender's own words, and
                             the only place it is rendered. --}}
                        <p class="fb-message">{{ $f['message'] }}</p>

                        <div class="fb-who">
                            <strong>{{ $f['author_role'] }}</strong>
                            @if($f['author_name']) &middot; {{ $f['author_name'] }} @endif
                            &middot; {{ $f['author_email'] ?: 'no email on file' }}
                            @if($f['academy']) &middot; {{ $f['academy'] }} @endif
                            {{-- The portal path the form was opened from. Free text
                                 from the client, rendered escaped above; it is the
                                 fastest way to reproduce a report. --}}
                            @if($f['page']) &middot; <span class="fb-page">{{ $f['page'] }}</span> @endif
                        </div>

                        @if($f['resolution_note'])
                            <div class="note-shown">Note: {{ $f['resolution_note'] }}</div>
                        @endif

                        @if(! $f['settled'])
                            <form method="POST" action="{{ route('admin.lms.feedback.update', $f['id']) }}" class="triage-form">
                                @csrf
                                <textarea name="resolution_note" rows="2" maxlength="2000"
                                          placeholder="Optional note for ourselves, e.g. the ticket this became.">{{ old('resolution_note') }}</textarea>
                                <button type="submit" name="status" value="planned" class="action">Planned</button>
                                <button type="submit" name="status" value="done" class="action primary">Done</button>
                                <button type="submit" name="status" value="dismissed" class="action danger">Dismiss</button>
                                <span class="triage-hint">Nothing is emailed to the sender. "New" is the badge on the sidebar; planned, done and dismissed all clear it.</span>
                            </form>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection
