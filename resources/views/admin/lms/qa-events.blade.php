@extends('admin.lms.layout')

@section('title', 'QA Testing')
@php ($activeLmsPage = 'qa-events') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); }
        .flash-warn { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--warning-soft); color: var(--warning); border: 1px solid rgba(217, 119, 6, 0.22); }
        .stack { display: grid; gap: 18px; }

        .event-block { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; background: #fff; }
        .event-head { padding: 14px 18px; background: #f7f9fc; border-bottom: 1px solid var(--line); }
        .event-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .event-name { font-weight: 800; color: var(--ink); font-size: 15px; }
        .event-meta { font-size: 12px; color: var(--muted); margin-top: 4px; }

        .state { border-radius: 999px; padding: 3px 10px; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.05em; }
        .state.on { background: var(--success-soft); color: var(--success); }
        .state.off { background: var(--danger-soft); color: var(--danger); }
        .state.dark { background: #eef1f5; color: var(--muted); }

        /* The two links the team runs the day from. Given their own block rather
           than tucked into the meta line, because "copy the host link" happens
           under pressure and must be findable at a glance. */
        .links { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-top: 12px; }
        .link-box { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; background: #fff; }
        .link-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 800; color: var(--muted); margin-bottom: 6px; }
        .link-row { display: flex; gap: 8px; align-items: center; }
        .link-url { flex: 1 1 auto; min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 11px; color: #33465a; overflow-wrap: anywhere; }
        .link-row .action { cursor: pointer; flex: 0 0 auto; }

        .slot-row { border-top: 1px solid var(--line); padding: 14px 18px; }
        .slot-row:first-of-type { border-top: 0; }
        .slot-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .slot-label { font-weight: 700; color: var(--ink); font-size: 13px; }
        .slot-window { font-size: 12px; color: var(--muted); }
        .slot-counts { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }

        table.testers { width: 100%; border-collapse: collapse; }
        table.testers th { text-align: left; font-size: 10px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--muted); font-weight: 800; padding: 6px 8px; border-bottom: 1px solid var(--line); }
        table.testers td { padding: 9px 8px; border-bottom: 1px solid var(--line); font-size: 12px; color: #33465a; vertical-align: middle; }
        table.testers tr:last-child td { border-bottom: 0; }
        table.testers tr.is-removed td { opacity: 0.5; text-decoration: line-through; }
        .tester-name { font-weight: 700; color: var(--ink); }
        .tester-sub { font-size: 11px; color: var(--muted); }
        .row-actions { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
        .row-actions form { display: inline; }
        .row-actions .action { cursor: pointer; padding: 6px 10px; }
        .row-actions .action.danger { border-color: rgba(185, 28, 28, 0.30); color: var(--danger); }

        .no-slots { padding: 14px 18px; font-size: 13px; color: var(--muted); }
        @media (max-width: 720px) {
            table.testers thead { display: none; }
            table.testers td { display: block; border-bottom: 0; padding: 3px 0; }
            table.testers tr { display: block; border-bottom: 1px solid var(--line); padding: 10px 0; }
            .row-actions { justify-content: flex-start; margin-top: 6px; }
        }
    </style>
@endsection

@section('toolbar_title', 'QA Testing')
@section('toolbar_text', 'Testing passes for outside products. Each person gets a link; no account, no password.')

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

        @unless($qaEnabled ?? false)
            {{-- The page itself does not 404 when the flag is off, because the host
                 still needs to read the list and copy links. But signup and joining
                 DO 404, so this has to be stated rather than left to be discovered
                 by a tester. --}}
            <div class="flash-warn">
                QA testing is switched off. Registration and join links return "not found" until
                <strong>QA_EVENTS_ENABLED=true</strong> is set and the config is cached.
                Everything below stays readable in the meantime.
            </div>
        @endunless

        @if(($events ?? collect())->isEmpty())
            <div class="panel">
                <div class="panel-empty">
                    No QA events yet. Create one with
                    <code>php artisan qa:setup-event iungo --slot="10:00-11:30"</code>.
                </div>
            </div>
        @else
            @foreach($events as $event)
                <div class="event-block">
                    <div class="event-head">
                        <div class="event-top">
                            <span class="event-name">{{ $event['name'] }}</span>
                            @if(! $event['is_active'])
                                <span class="state dark">Switched off</span>
                            @elseif($event['is_open'])
                                <span class="state on">Open now</span>
                            @else
                                <span class="state off">Closed</span>
                            @endif
                            <span class="chip" style="margin-left:auto">
                                <strong>{{ $event['total_testers'] }}</strong> signed up
                            </span>
                        </div>
                        <div class="event-meta">
                            /{{ $event['slug'] }}
                            @if($event['academy'])
                                &middot; {{ $event['academy'] }}
                            @endif
                            @if($event['starts_at'] || $event['ends_at'])
                                &middot; {{ $event['starts_at'] ?: 'no start' }} to {{ $event['ends_at'] ?: 'no end' }}
                            @else
                                &middot; no time window set, so signup stays open until the event is switched off
                            @endif
                        </div>

                        <div class="links">
                            <div class="link-box">
                                <div class="link-label">Signup page, share this one</div>
                                <div class="link-row">
                                    <span class="link-url" id="reg-{{ $event['id'] }}">{{ $event['register_url'] }}</span>
                                    <button type="button" class="action" data-copy-target="reg-{{ $event['id'] }}">Copy</button>
                                </div>
                            </div>
                            <div class="link-box">
                                <div class="link-label">Host room, keep this private</div>
                                <div class="link-row">
                                    <span class="link-url" id="host-{{ $event['id'] }}">{{ $event['host_url'] }}</span>
                                    <button type="button" class="action" data-copy-target="host-{{ $event['id'] }}">Copy</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    @if(count($event['slots']) === 0)
                        <div class="no-slots">No sessions on this event yet.</div>
                    @else
                        @foreach($event['slots'] as $slot)
                            <div class="slot-row">
                                <div class="slot-top">
                                    <span class="slot-label">{{ $slot['label'] }}</span>
                                    @if($slot['is_open'])
                                        <span class="state on">Open</span>
                                    @else
                                        <span class="state off">Closed</span>
                                    @endif
                                    @if($slot['window'])
                                        <span class="slot-window">{{ $slot['window'] }}</span>
                                    @endif

                                    <div class="slot-counts">
                                        <span class="chip"><strong>{{ $slot['signed_up'] }}</strong> signed up@if($slot['capacity']) of {{ $slot['capacity'] }}@endif</span>
                                        {{-- Attended, not signed up, is the number to watch during
                                             the session: it is the only one that says whether the
                                             room is actually filling. --}}
                                        <span class="chip"><strong>{{ $slot['attended'] }}</strong> in the room</span>
                                    </div>
                                </div>

                                @if($slot['testers']->isEmpty())
                                    <div class="no-slots" style="padding:0">Nobody has picked this session yet.</div>
                                @else
                                    <table class="testers">
                                        <thead>
                                            <tr>
                                                <th>Tester</th>
                                                <th>Phone</th>
                                                <th>Opened room</th>
                                                <th>Link sent</th>
                                                <th></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($slot['testers'] as $tester)
                                                <tr class="{{ $tester['removed'] ? 'is-removed' : '' }}">
                                                    <td>
                                                        <div class="tester-name">{{ $tester['name'] }}</div>
                                                        <div class="tester-sub">{{ $tester['email'] }}</div>
                                                    </td>
                                                    <td>{{ $tester['phone'] ?: 'not given' }}</td>
                                                    <td>{{ $tester['joined_at'] ?: 'not yet' }}</td>
                                                    <td>{{ $tester['emailed_at'] ?: 'not sent' }}</td>
                                                    <td>
                                                        <div class="row-actions">
                                                            <button type="button" class="action" data-copy-target="join-{{ $tester['id'] }}">Copy link</button>
                                                            <span id="join-{{ $tester['id'] }}" hidden>{{ $tester['join_url'] }}</span>

                                                            @if($tester['removed'])
                                                                {{-- A removed tester cannot be re-sent a link (their pass is
                                                                     cancelled), so the only useful action on the row is the
                                                                     way back. Removing the wrong person is a one-click
                                                                     mistake, and it must not need a database edit. --}}
                                                                <form method="POST" action="{{ route('admin.lms.qa.restore', $tester['id']) }}">
                                                                    @csrf
                                                                    <button type="submit" class="action">Restore</button>
                                                                </form>
                                                            @else
                                                                <form method="POST" action="{{ route('admin.lms.qa.resend', $tester['id']) }}">
                                                                    @csrf
                                                                    <button type="submit" class="action">Resend</button>
                                                                </form>
                                                                <form method="POST" action="{{ route('admin.lms.qa.remove', $tester['id']) }}"
                                                                      onsubmit="return confirm('Cancel {{ $tester['name'] }}\'s testing pass? Their link stops working immediately.');">
                                                                    @csrf
                                                                    <button type="submit" class="action danger">Remove</button>
                                                                </form>
                                                            @endif
                                                        </div>
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                @endif
                            </div>
                        @endforeach
                    @endif
                </div>
            @endforeach
        @endif
    </div>

    <script>
        // Copy buttons work off the id of the element holding the text, so the
        // URL is always on screen and selectable by hand if the clipboard API
        // is refused (it is, outside a secure context and in some embedded
        // browsers). The fallback selects the text so Ctrl+C still works.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-copy-target]');
            if (!btn) return;

            var el = document.getElementById(btn.getAttribute('data-copy-target'));
            if (!el) return;

            var text = el.textContent.trim();
            var done = function () {
                var was = btn.textContent;
                btn.textContent = 'Copied';
                setTimeout(function () { btn.textContent = was; }, 1400);
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done, function () { select(el); });
            } else {
                select(el);
            }
        });

        function select(el) {
            var range = document.createRange();
            range.selectNodeContents(el);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        }
    </script>
@endsection
