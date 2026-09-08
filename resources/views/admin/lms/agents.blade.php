@php ($activeLmsPage = 'agents') @endphp
@extends('admin.lms.layout')

@section('title', 'Agent Applications')
@section('toolbar_title', 'Agent Applications')
@section('toolbar_text', 'Review, approve, or reject agent applications.')
@section('toolbar_actions')
    <div class="chip">Applications <strong>{{ $agents->total() }}</strong></div>
    <a href="{{ route('admin.lms.index') }}" class="action">Back to dashboard</a>
@endsection

@section('head')
<style>
    .data-table tbody tr.agent-row { cursor: pointer; transition: background 0.12s ease; }
    .data-table tbody tr.agent-row:hover { background: #f7fafc; }
    .data-table tbody tr.agent-row.row-open { background: var(--panel-alt); }
    .answers-row { display: none; }
    .answers-row.open { display: table-row; }
    .answers-row > td { padding: 0 14px 16px; background: var(--panel-alt); border-bottom: 1px solid var(--line); }

    .expand-icon {
        display: inline-grid; place-items: center;
        width: 20px; height: 20px;
        color: var(--muted);
        transition: transform 0.2s ease;
    }
    .expand-icon svg { width: 14px; height: 14px; }
    .expand-icon.open { transform: rotate(90deg); }

    .agent-cell { display: flex; align-items: center; gap: 10px; }
    .agent-avatar {
        width: 34px; height: 34px; flex: none;
        border-radius: 10px;
        background: var(--sidebar);
        color: rgba(255, 255, 255, 0.92);
        font-size: 13px; font-weight: 700;
        display: grid; place-items: center;
        text-transform: uppercase; letter-spacing: 0.04em;
    }
    .agent-name { font-weight: 700; color: var(--ink); font-size: 13px; }

    .ref-code {
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: 11px;
        background: var(--panel-alt);
        border: 1px solid var(--line);
        border-radius: 7px;
        padding: 3px 8px;
        color: #30465b;
        white-space: nowrap;
    }

    .action-cell { white-space: nowrap; }
    .btn-approve, .btn-reject {
        display: inline-block;
        border: none;
        border-radius: 10px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        transition: background 0.15s ease, color 0.15s ease;
    }
    .btn-approve { background: var(--success-soft); color: var(--success); }
    .btn-approve:hover { background: var(--success); color: #fff; }
    .btn-reject { background: var(--danger-soft); color: var(--danger); }
    .btn-reject:hover { background: var(--danger); color: #fff; }

    .answers-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .answer-card { background: #fff; border: 1px solid var(--line); border-radius: 10px; padding: 12px 14px; }
    .answer-card.full { grid-column: 1 / -1; }
    .answer-card .label {
        font-size: 10px; font-weight: 700; text-transform: uppercase;
        letter-spacing: 0.05em; color: var(--muted); margin-bottom: 4px;
    }
    .answer-card .value { font-size: 13px; line-height: 1.5; color: var(--ink); white-space: pre-wrap; word-break: break-word; }

    .empty-icon { display: grid; place-items: center; margin: 0 auto 10px; opacity: 0.45; color: var(--muted); }
    .empty-icon svg { width: 34px; height: 34px; }

    .panel-footer { padding: 12px 18px; border-top: 1px solid var(--line); background: var(--panel-alt); }

    @media (max-width: 900px) {
        .answers-inner { grid-template-columns: 1fr; }
    }
</style>
<script>
function toggleRow(el) {
    var tr = el.closest('tr');
    var answersRow = tr.nextElementSibling;
    var icon = tr.querySelector('.expand-icon');
    if (answersRow && answersRow.classList.contains('answers-row')) {
        answersRow.classList.toggle('open');
        tr.classList.toggle('row-open');
        if (icon) icon.classList.toggle('open');
    }
}
</script>
@endsection

@section('content')
<div class="panel">
    <div class="panel-head">
        <div class="panel-title">Applications</div>
        <div></div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th style="width:24px"></th>
                    <th>Name &amp; Contact</th>
                    <th>Qualification</th>
                    <th>Referral Code</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($agents as $agent)
                @php ($answers = $agent->custom_answers ?? []) @endphp
                <tr class="agent-row" onclick="toggleRow(this)">
                    <td>
                        <span class="expand-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></svg>
                        </span>
                    </td>
                    <td>
                        <div class="agent-cell">
                            <div class="agent-avatar">{{ strtoupper(substr(($agent->name ?? '?'), 0, 1)) }}</div>
                            <div style="min-width:0">
                                <div class="agent-name">{{ $agent->name }}</div>
                                <div class="row-sub">{{ $agent->email }}</div>
                                <div class="row-sub">{{ $agent->phone }}</div>
                            </div>
                        </div>
                    </td>
                    <td style="font-size:12px">{{ $agent->qualification }}</td>
                    <td><span class="ref-code">{{ $agent->referral_code ?? 'N/A' }}</span></td>
                    <td>
                        @php ($statusState = ['approved' => 'active', 'rejected' => 'danger'][$agent->status] ?? 'warning') @endphp
                        <span class="course-state {{ $statusState }}">{{ $agent->status }}</span>
                    </td>
                    <td class="num">
                        <div>{{ $agent->created_at->format('d M Y') }}</div>
                        @if($agent->approved_at)
                            <div class="row-sub">Approved: {{ $agent->approved_at->format('d M Y') }}</div>
                        @endif
                    </td>
                    <td class="action-cell">
                        @if($agent->status === 'pending')
                        <form method="POST" action="{{ route('admin.lms.agents.approve', $agent->id) }}" style="display:inline" onclick="event.stopPropagation()">
                            @csrf
                            <button type="submit" class="btn-approve">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('admin.lms.agents.reject', $agent->id) }}" style="display:inline;margin-left:4px" onclick="event.stopPropagation()">
                            @csrf
                            <button type="submit" class="btn-reject">Reject</button>
                        </form>
                        @endif
                        <form method="POST" action="{{ route('admin.lms.agents.delete', $agent->id) }}" style="display:inline;margin-left:4px" onsubmit="return confirm('Delete this agent and all their data?')" onclick="event.stopPropagation()">
                            @csrf
                            <button type="submit" style="background:#557082;color:#fff;border:none;border-radius:10px;padding:7px 12px;font-size:12px;font-weight:700;font-family:inherit;cursor:pointer;transition:background 0.15s ease" onmouseover="this.style.background='#b91c1c'" onmouseout="this.style.background='#557082'">Delete</button>
                        </form>
                    </td>
                </tr>
                <tr class="answers-row">
                    <td colspan="7">
                        <div class="answers-inner">
                            <div class="answer-card full">
                                <div class="label">Address</div>
                                <div class="value">{{ $agent->home_address }}</div>
                            </div>
                            @if(is_array($answers) && count($answers))
                            <div class="answer-card">
                                <div class="label">Target Students</div>
                                <div class="value">{{ $answers['target_students'] ?? 'N/A' }}</div>
                            </div>
                            <div class="answer-card">
                                <div class="label">Experience</div>
                                <div class="value">{{ $answers['experience'] ?? 'N/A' }}</div>
                            </div>
                            <div class="answer-card full">
                                <div class="label">Courses to Promote</div>
                                <div class="value">{{ $answers['courses_to_promote'] ?? 'N/A' }}</div>
                            </div>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" style="padding:48px 24px;text-align:center;color:var(--muted)">
                        <div class="empty-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/></svg>
                        </div>
                        <strong style="color:var(--ink)">No agent applications yet</strong>
                        <p style="font-size:13px;margin:6px 0 0">Applications will appear here once agents start signing up.</p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        {{ $agents->links() }}
    </div>
</div>
@endsection
