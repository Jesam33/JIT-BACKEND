@php $activeLmsPage = 'agent-withdrawals'; @endphp
@extends('admin.lms.layout')

@section('title', 'Withdrawal Requests — LMS Admin')
@section('toolbar_title', 'Withdrawal Requests')
@section('toolbar_text', 'Review and process agent payout requests.')
@section('toolbar_actions')
    <div class="chip">Pending <strong>{{ $withdrawals->total() }}</strong></div>
    <a href="{{ route('admin.lms.agents.index') }}" class="action">Back to Agents</a>
@endsection

@section('head')
<style>
    .wd-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .wd-table th { background: var(--panel-alt); text-align: left; padding: 10px 12px; border-bottom: 2px solid var(--line); color: var(--muted); font-weight: 700; font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; white-space: nowrap; }
    .wd-table td { padding: 10px 12px; border-bottom: 1px solid var(--line); vertical-align: middle; }
    .wd-table tr:hover td { background: var(--panel-alt); }
    .badge-pending { background: #fef3c7; color: #92400e; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .badge-paid { background: #d1fae5; color: #065f46; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
    .btn-pay { background: #059669; color: #fff; border: none; border-radius: 6px; padding: 6px 14px; font-size: 11px; font-weight: 700; cursor: pointer; }
    .btn-pay:hover { background: #047857; }
    .empty { text-align: center; padding: 40px; color: var(--muted); }
    .bank-details { font-size: 12px; color: var(--text); }
    .bank-details strong { color: var(--muted); }
</style>
@endsection

@section('content')
<h3 style="margin-bottom:12px;font-size:15px;color:var(--text);">Pending Withdrawals</h3>
<div style="overflow-x:auto">
    <table class="wd-table">
        <thead>
            <tr>
                <th>Agent</th>
                <th>Contact</th>
                <th>Bank Details</th>
                <th>Amount</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            @forelse($withdrawals as $wd)
            @php $a = $wd->agent; @endphp
            <tr>
                <td><strong>{{ $a->name ?? 'Unknown' }}</strong></td>
                <td style="font-size:12px;color:var(--muted)">
                    <div>{{ $a->email ?? '' }}</div>
                    <div>{{ $a->phone ?? '' }}</div>
                </td>
                <td class="bank-details">
                    @if($a && ($a->bank_name || $a->account_number))
                        <div><strong>Bank:</strong> {{ $a->bank_name }}</div>
                        <div><strong>Acc:</strong> {{ $a->account_number }}</div>
                        <div><strong>Name:</strong> {{ $a->account_name }}</div>
                    @else
                        <span style="color:var(--muted)">Not provided</span>
                    @endif
                </td>
                <td><strong>₦{{ number_format(abs($wd->commission_amount), 2) }}</strong></td>
                <td style="font-size:12px;color:var(--muted);white-space:nowrap">{{ $wd->created_at->format('d M Y') }}</td>
                <td>
                    <form method="POST" action="{{ route('admin.lms.agents.pay', $a->id) }}" onsubmit="return confirm('Mark all commissions for {{ $a->name }} as paid?')">
                        @csrf
                        <button type="submit" class="btn-pay">Mark Paid</button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="empty">No pending withdrawal requests.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div style="margin-top:16px">{{ $withdrawals->links() }}</div>

@if($paid->count() > 0)
<hr style="margin:32px 0;border:none;border-top:1px solid var(--line);">

<h3 style="margin-bottom:12px;font-size:15px;color:var(--text);">Paid Withdrawals</h3>
<div style="overflow-x:auto">
    <table class="wd-table">
        <thead>
            <tr>
                <th>Agent</th>
                <th>Bank Details</th>
                <th>Amount</th>
                <th>Date</th>
            </tr>
        </thead>
        <tbody>
            @foreach($paid as $wd)
            @php $a = $wd->agent; @endphp
            <tr>
                <td><strong>{{ $a->name ?? 'Unknown' }}</strong></td>
                <td class="bank-details" style="font-size:12px;color:var(--muted)">
                    @if($a && ($a->bank_name || $a->account_number))
                        {{ $a->bank_name }} - {{ $a->account_number }}
                    @else
                        <span style="color:var(--muted)">Not provided</span>
                    @endif
                </td>
                <td><strong>₦{{ number_format(abs($wd->commission_amount), 2) }}</strong></td>
                <td style="font-size:12px;color:var(--muted);white-space:nowrap">{{ $wd->created_at->format('d M Y') }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div style="margin-top:16px">{{ $paid->links() }}</div>
@endif
@endsection