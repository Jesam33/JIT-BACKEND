@php $activeLmsPage = 'agents'; @endphp
@extends('admin.lms.layout')

@section('title', 'Agents — LMS Admin')
@section('toolbar_title', 'Agent Applications')
@section('toolbar_text', 'Review, approve, or reject agent applications.')
@section('toolbar_actions')
    <div class="chip">Applications <strong>{{ $agents->total() }}</strong></div>
    <a href="{{ route('admin.lms.index') }}" class="action">Back to dashboard</a>
@endsection

@section('head')
<style>
    .agents-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .agents-table thead { position: sticky; top: 0; z-index: 2; }
    .agents-table th { background: var(--panel-alt); text-align: left; padding: 10px 12px; border-bottom: 2px solid var(--line); color: var(--muted); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; }
    .agents-table td { padding: 6px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
    .agents-table tbody tr { cursor: pointer; transition: background 0.12s; }
    .agents-table tbody tr:hover { background: var(--panel-alt); }
    .agents-table tbody tr.row-open { background: var(--panel-alt); }
    .agents-table tbody tr.row-open + .answers-row td { background: var(--panel-alt); padding-top: 0; }
    .agents-table .name-cell { font-weight: 700; color: var(--text); }
    .agents-table .contact-cell { font-size: 12px; color: var(--muted); line-height: 1.5; }
    .agents-table .contact-cell div + div { margin-top: 1px; }
    .agents-table .qual-cell { color: var(--text); font-size: 12px; }
    .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-approved { background: #d1fae5; color: #065f46; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }
    .ref-code { font-family: monospace; font-size: 11px; background: var(--panel); padding: 2px 8px; border-radius: 4px; border: 1px solid var(--line); }
    .date-cell { font-size: 12px; color: var(--muted); white-space: nowrap; }
    .action-cell { white-space: nowrap; }
    .btn-approve { background: #059669; color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
    .btn-approve:hover { background: #047857; }
    .btn-reject { background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-size: 11px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
    .btn-reject:hover { background: #b91c1c; }
    .expand-icon { display: inline-block; width: 20px; text-align: center; color: var(--muted); font-size: 10px; transition: transform 0.2s; }
    .expand-icon.open { transform: rotate(90deg); }
    .answers-row { display: none; }
    .answers-row.open { display: table-row; }
    .answers-row td { padding: 0 12px 16px; border-bottom: 1px solid var(--line); }
    .answers-inner { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .answer-card { background: var(--panel); border: 1px solid var(--line); border-radius: 8px; padding: 12px 14px; }
    .answer-card.full { grid-column: 1 / -1; }
    .answer-card .label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--muted); margin-bottom: 4px; }
    .answer-card .value { font-size: 13px; line-height: 1.5; color: var(--text); white-space: pre-wrap; word-break: break-word; }
    .empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
    .empty-state p { font-size: 14px; margin-top: 6px; }
    .approved-date { font-size: 11px; color: var(--muted); }
    @media (max-width: 900px) {
        .agents-table { font-size: 12px; }
        .agents-table th, .agents-table td { padding: 6px 8px; }
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
<div style="overflow-x:auto">
    <table class="agents-table">
        <thead>
            <tr>
                <th style="width:24px"></th>
                <th>Name & Contact</th>
                <th>Qualification</th>
                <th>Referral Code</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
            @php $answers = $agent->custom_answers ?? []; @endphp
            <tr onclick="toggleRow(this)">
                <td><span class="expand-icon">&#9654;</span></td>
                <td>
                    <div class="name-cell">{{ $agent->name }}</div>
                    <div class="contact-cell">
                        <div>{{ $agent->email }}</div>
                        <div>{{ $agent->phone }}</div>
                    </div>
                </td>
                <td class="qual-cell">{{ $agent->qualification }}</td>
                <td><code class="ref-code">{{ $agent->referral_code ?? '&mdash;' }}</code></td>
                <td><span class="badge badge-{{ $agent->status }}">{{ $agent->status }}</span></td>
                <td class="date-cell">
                    <div>{{ $agent->created_at->format('d M Y') }}</div>
                    @if($agent->approved_at)
                        <div class="approved-date">Approved: {{ $agent->approved_at->format('d M Y') }}</div>
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
                        <button type="submit" style="background:#6b7280;color:#fff;border:none;border-radius:6px;padding:6px 14px;font-size:11px;font-weight:700;cursor:pointer;transition:background 0.15s;" onmouseover="this.style.background='#dc2626'" onmouseout="this.style.background='#6b7280'">Delete</button>
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
            <tr><td colspan="7" style="text-align:center;padding:60px 20px;color:var(--muted)">
                <div style="font-size:32px;margin-bottom:8px;">&#128233;</div>
                <strong>No agent applications yet</strong>
                <p style="font-size:14px;margin-top:6px;">Applications will appear here once agents start signing up.</p>
            </td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:20px">
    {{ $agents->links() }}
</div>
@endsection
