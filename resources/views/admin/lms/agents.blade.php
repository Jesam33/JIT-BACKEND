@php($activeLmsPage = 'agents')
@extends('admin.lms.layout')

@section('title', 'Agents — LMS Admin')
@section('toolbar_title', 'Agent Applications')
@section('toolbar_text', 'Review, approve, or reject agent applications.')

@section('head')
<style>
    .agent-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .agent-table th { text-align: left; padding: 10px 14px; border-bottom: 1px solid var(--line); color: var(--muted); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; }
    .agent-table td { padding: 10px 14px; border-bottom: 1px solid var(--line); vertical-align: top; }
    .agent-table tr:hover td { background: var(--panel-alt); }
    .badge { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .badge-pending { background: #fef3c7; color: #92400e; }
    .badge-approved { background: #d1fae5; color: #065f46; }
    .badge-rejected { background: #fee2e2; color: #991b1b; }
    .answers-wrap { max-width: 280px; }
    .answers-wrap details { margin-top: 4px; }
    .answers-wrap summary { cursor: pointer; color: var(--accent); font-size: 12px; font-weight: 600; }
    .answers-wrap .answer-text { margin-top: 6px; padding: 8px; background: var(--panel-alt); border-radius: 6px; font-size: 12px; line-height: 1.5; white-space: pre-wrap; }
</style>
@endsection

@section('content')
<div style="overflow-x:auto">
    <table class="agent-table">
        <thead>
            <tr>
                <th>Name</th>
                <th>Contact</th>
                <th>Qualification</th>
                <th>Answers</th>
                <th>Referral Code</th>
                <th>Status</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($agents as $agent)
            @php $answers = $agent->custom_answers ?? []; @endphp
            <tr>
                <td><strong>{{ $agent->name }}</strong></td>
                <td>
                    <div>{{ $agent->email }}</div>
                    <div style="font-size:11px;color:var(--muted)">{{ $agent->phone }}</div>
                    <div style="font-size:11px;color:var(--muted)">{{ Str::limit($agent->home_address, 40) }}</div>
                </td>
                <td>{{ $agent->qualification }}</td>
                <td class="answers-wrap">
                    <details>
                        <summary>View answers</summary>
                        @if(is_array($answers))
                            <div class="answer-text">
                                <strong>Target students:</strong> {{ $answers['target_students'] ?? 'N/A' }}
                            </div>
                            <div class="answer-text" style="margin-top:4px">
                                <strong>Experience:</strong> {{ $answers['experience'] ?? 'N/A' }}
                            </div>
                            <div class="answer-text" style="margin-top:4px">
                                <strong>Courses to promote:</strong> {{ $answers['courses_to_promote'] ?? 'N/A' }}
                            </div>
                        @else
                            <div class="answer-text">{{ $answers }}</div>
                        @endif
                    </details>
                </td>
                <td><code>{{ $agent->referral_code }}</code></td>
                <td>
                    <span class="badge badge-{{ $agent->status }}">
                        {{ $agent->status }}
                    </span>
                </td>
                <td style="font-size:11px;color:var(--muted);white-space:nowrap">{{ $agent->created_at->format('d M Y') }}</td>
                <td style="white-space:nowrap">
                    @if($agent->status === 'pending')
                    <form method="POST" action="{{ route('admin.lms.agents.approve', $agent->id) }}" style="display:inline">
                        @csrf
                        <button type="submit" style="background:#059669;color:#fff;border:none;border-radius:6px;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer">Approve</button>
                    </form>
                    <form method="POST" action="{{ route('admin.lms.agents.reject', $agent->id) }}" style="display:inline;margin-left:4px">
                        @csrf
                        <button type="submit" style="background:#dc2626;color:#fff;border:none;border-radius:6px;padding:5px 14px;font-size:12px;font-weight:600;cursor:pointer">Reject</button>
                    </form>
                    @else
                    <span style="font-size:11px;color:var(--muted)">{{ $agent->approved_at?->format('d M Y') ?? '—' }}</span>
                    @endif
                </td>
            </tr>
            @empty
            <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted)">No agent applications yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div style="margin-top:20px">
    {{ $agents->links() }}
</div>
@endsection
