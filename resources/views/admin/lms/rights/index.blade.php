@extends('admin.lms.layout')

@section('title', 'Rights Requests')
@php ($activeLmsPage = 'rights') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); }
        .stack { display: grid; gap: 18px; }

        .request-row { padding: 16px 18px; border-bottom: 1px solid var(--line); }
        .request-row:last-child { border-bottom: 0; }
        .request-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
        .request-type { font-weight: 700; color: var(--ink); font-size: 13px; }
        .request-age { margin-left: auto; font-size: 12px; color: var(--muted); }
        .request-details { font-size: 13px; color: #33465a; line-height: 1.55; white-space: pre-wrap; margin: 0 0 10px; }
        .request-who { font-size: 12px; color: var(--muted); }
        .request-who strong { color: #33465a; }

        .respond-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; margin-top: 10px; }
        .respond-form textarea { flex: 1 1 260px; min-width: 220px; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 9px 11px; font: inherit; font-size: 12px; resize: vertical; }
        .respond-form textarea:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-soft); }
        .respond-form .action { cursor: pointer; }
        .note-shown { font-size: 12px; color: var(--muted); margin-top: 8px; font-style: italic; }
        .respond-hint { font-size: 12px; color: var(--muted); flex-basis: 100%; }
    </style>
@endsection

@section('toolbar_title', 'Rights Requests')
@section('toolbar_text', 'Data requests from students, staff and academy owners. Answering one emails the person who asked.')

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

        @if(($showClosed ?? false))
            <div class="panel">
                <div class="panel-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="font-size:13px;color:var(--muted)">Showing completed and refused requests too.</span>
                    <a href="{{ route('admin.lms.rights.index') }}" class="action">Show open only</a>
                </div>
            </div>
        @endif

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">Queue</div>
            </div>

            @if(count($requests ?? []) === 0)
                <div class="panel-empty">No requests waiting.</div>
            @else
                @foreach($requests as $r)
                    <div class="request-row">
                        <div class="request-top">
                            <span class="request-type">{{ $r['type_label'] }}</span>
                            <span class="course-state {{ $r['status_class'] }}">{{ str_replace('_', ' ', ucfirst($r['status'])) }}</span>
                            <span class="request-age">{{ $r['age'] }}</span>
                        </div>

                        <p class="request-details">{{ $r['details'] }}</p>

                        {{-- The email is a SNAPSHOT taken when the request was made
                             (see the model). It is the only way to reply once an
                             erasure has anonymised the account it names, so it is
                             shown, not looked up. --}}
                        <div class="request-who">
                            <strong>{{ $r['requester_role'] }}</strong>
                            &middot; {{ $r['requester_email'] ?: 'no email on file' }}
                            @if($r['academy']) &middot; {{ $r['academy'] }} @endif
                        </div>

                        @if($r['response_note'])
                            <div class="note-shown">Reply sent: {{ $r['response_note'] }}</div>
                        @endif

                        @if(! $r['settled'])
                            <form method="POST" action="{{ route('admin.lms.rights.update', $r['id']) }}" class="respond-form">
                                @csrf
                                <textarea name="response_note" rows="2" maxlength="2000"
                                          placeholder="What you are telling the requester. This is emailed to them.">{{ old('response_note') }}</textarea>
                                <button type="submit" name="status" value="in_progress" class="action">Mark in progress</button>
                                <button type="submit" name="status" value="completed" class="action primary">Complete &amp; reply</button>
                                <button type="submit" name="status" value="refused" class="action danger">Refuse</button>
                                <span class="respond-hint">Complete and Refuse both email the requester with the note above. An erasure cannot be completed for a student with payment records.</span>
                            </form>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    </div>
@endsection
