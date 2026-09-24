@extends('admin.lms.layout')

@section('title', 'Academy Reports')
@php ($activeLmsPage = 'reports') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); }
        .stack { display: grid; gap: 18px; }

        /* Grouped BY ACADEMY, not by report. Five complaints about one academy
           are one problem to investigate, and a flat list makes them read as
           five, which is how a pattern gets missed. */
        .academy-block { border: 1px solid var(--line); border-radius: 14px; overflow: hidden; margin-bottom: 18px; background: #fff; }
        .academy-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding: 14px 18px; background: #f7f9fc; border-bottom: 1px solid var(--line); }
        .academy-name { font-weight: 800; color: var(--ink); font-size: 15px; }
        .academy-meta { font-size: 12px; color: var(--muted); }
        .academy-actions { margin-left: auto; display: flex; gap: 8px; flex-wrap: wrap; }
        .academy-actions form { display: inline; }
        .academy-actions .action { cursor: pointer; }
        .academy-actions .action.danger { border-color: rgba(185, 28, 28, 0.30); color: var(--danger); }

        .report-row { padding: 16px 18px; border-bottom: 1px solid var(--line); }
        .report-row:last-child { border-bottom: 0; }
        .report-top { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 6px; }
        .report-cat { font-weight: 700; color: var(--ink); font-size: 13px; }
        .report-age { margin-left: auto; font-size: 12px; color: var(--muted); }
        .report-details { font-size: 13px; color: #33465a; line-height: 1.55; white-space: pre-wrap; margin: 0 0 10px; }
        .report-who { font-size: 12px; color: var(--muted); }

        .resolve-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-start; margin-top: 10px; }
        .resolve-form textarea { flex: 1 1 260px; min-width: 220px; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 9px 11px; font: inherit; font-size: 12px; resize: vertical; }
        .resolve-form textarea:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-soft); }
        .resolve-form .action { cursor: pointer; }
        .note-shown { font-size: 12px; color: var(--muted); margin-top: 8px; font-style: italic; }
    </style>
@endsection

@section('toolbar_title', 'Academy Reports')
@section('toolbar_text', 'What students have told us about their academies. Only Jorsas Tech sees this page.')

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

        @if(($showResolved ?? false))
            <div class="panel">
                <div class="panel-body" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
                    <span style="font-size:13px;color:var(--muted)">Showing resolved and dismissed reports too.</span>
                    <a href="{{ route('admin.lms.reports.index') }}" class="action">Show open only</a>
                </div>
            </div>
        @endif

        @if(count($groups ?? []) === 0)
            <div class="panel">
                <div class="panel-empty">No reports waiting. Nothing has been flagged by a student.</div>
            </div>
        @else
            @foreach($groups as $group)
                @php $tenant = $group['tenant']; @endphp
                <div class="academy-block">
                    <div class="academy-head">
                        <div>
                            <div class="academy-name">{{ $tenant?->name ?? 'Unknown academy' }}</div>
                            <div class="academy-meta">
                                {{ $group['reports']->count() }} report{{ $group['reports']->count() === 1 ? '' : 's' }}
                                @if($tenant)
                                    &middot; {{ $tenant->slug }} &middot; currently {{ str_replace('_', ' ', $tenant->lifecycleState()) }}
                                @endif
                            </div>
                        </div>

                        {{-- The three academy-level actions are the SAME reversible
                             ones the Registered Institutes page uses, so an academy
                             actioned from here behaves identically there. --}}
                        @if($tenant)
                            <div class="academy-actions">
                                @if(in_array($tenant->lifecycleState(), ['purge_scheduled', 'purged'], true))
                                    <form method="POST" action="{{ route('admin.lms.institutes.reactivate', $tenant->id) }}">
                                        @csrf
                                        <button type="submit" class="action">Bring online</button>
                                    </form>
                                @elseif($tenant->lifecycleState() === 'deactivated')
                                    <form method="POST" action="{{ route('admin.lms.institutes.reactivate', $tenant->id) }}">
                                        @csrf
                                        <button type="submit" class="action">Bring online</button>
                                    </form>
                                @else
                                    <form method="POST" action="{{ route('admin.lms.institutes.deactivate', $tenant->id) }}">
                                        @csrf
                                        <button type="submit" class="action">Take offline</button>
                                    </form>
                                    <form method="POST" action="{{ route('admin.lms.institutes.delete', $tenant->id) }}"
                                          onsubmit="return confirm('Schedule {{ $tenant->name }} for closure? The academy keeps working for {{ \App\Models\Tenant::purgeWindowDays() }} days and can be restored from Registered Institutes until then.');">
                                        @csrf
                                        <button type="submit" class="action danger">Schedule closure</button>
                                    </form>
                                @endif
                            </div>
                        @endif
                    </div>

                    @foreach($group['reports'] as $report)
                        <div class="report-row">
                            <div class="report-top">
                                <span class="report-cat">{{ $report['category_label'] }}</span>
                                <span class="course-state {{ $report['status_class'] }}">{{ ucfirst($report['status']) }}</span>
                                <span class="report-age">{{ $report['age'] }}</span>
                            </div>
                            <p class="report-details">{{ $report['details'] }}</p>
                            <div class="report-who">From {{ $report['student'] }} &middot; {{ $report['student_email'] }}</div>

                            @if($report['resolution_note'])
                                <div class="note-shown">Note: {{ $report['resolution_note'] }}</div>
                            @endif

                            @if(! $report['settled'])
                                <form method="POST" action="{{ route('admin.lms.reports.update', $report['id']) }}" class="resolve-form">
                                    @csrf
                                    <textarea name="resolution_note" rows="2" maxlength="2000"
                                              placeholder="What you found and what happens next.">{{ old('resolution_note') }}</textarea>
                                    <button type="submit" name="status" value="reviewing" class="action">Mark reviewing</button>
                                    <button type="submit" name="status" value="resolved" class="action primary">Resolve</button>
                                    <button type="submit" name="status" value="dismissed" class="action danger">Dismiss</button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endforeach
        @endif
    </div>
@endsection
