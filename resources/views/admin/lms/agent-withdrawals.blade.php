@php ($activeLmsPage = 'agent-withdrawals') @endphp
@extends('admin.lms.layout')

@section('title', 'Withdrawal Requests')
@section('toolbar_title', 'Withdrawal Requests')
@section('toolbar_text', 'Review and process agent payout requests.')
@section('toolbar_actions')
    <div class="chip">Pending <strong>{{ $withdrawals->total() }}</strong></div>
    <a href="{{ route('admin.lms.agents.index') }}" class="action">Back to Agents</a>
@endsection

@section('head')
<style>
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
    .bank-details { font-size: 12px; color: #30465b; line-height: 1.6; }
    .bank-details .k { color: var(--muted); font-weight: 600; margin-right: 4px; }
    .btn-pay {
        display: inline-block;
        border: none;
        border-radius: 10px;
        padding: 7px 14px;
        font-size: 12px;
        font-weight: 700;
        font-family: inherit;
        cursor: pointer;
        background: var(--success);
        color: #fff;
        transition: background 0.15s ease;
    }
    .btn-pay:hover { background: #0d7a4c; }
    .panel-footer { padding: 12px 18px; border-top: 1px solid var(--line); background: var(--panel-alt); }
</style>
@endsection

@section('content')
<div class="panel">
    <div class="panel-head">
        <div class="panel-title">Pending Withdrawals</div>
        <div></div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Agent</th>
                    <th>Bank Details</th>
                    <th>Amount</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($withdrawals as $wd)
                @php ($a = $wd->agent) @endphp
                <tr>
                    <td>
                        <div class="agent-cell">
                            <div class="agent-avatar">{{ strtoupper(substr(($a->name ?? '?'), 0, 1)) }}</div>
                            <div style="min-width:0">
                                <div class="agent-name">{{ $a->name ?? 'Unknown' }}</div>
                                <div class="row-sub">{{ $a->email ?? '' }}</div>
                                <div class="row-sub">{{ $a->phone ?? '' }}</div>
                            </div>
                        </div>
                    </td>
                    <td class="bank-details">
                        @if($a && ($a->bank_name || $a->account_number))
                            <div><span class="k">Bank:</span> {{ $a->bank_name }}</div>
                            <div><span class="k">Acc:</span> {{ $a->account_number }}</div>
                            <div><span class="k">Name:</span> {{ $a->account_name }}</div>
                        @else
                            <span style="color:var(--muted)">Not provided</span>
                        @endif
                    </td>
                    <td class="num"><strong>₦{{ number_format(abs($wd->commission_amount), 2) }}</strong></td>
                    <td class="num">{{ $wd->created_at->format('d M Y') }}</td>
                    <td>
                        <form method="POST" action="{{ route('admin.lms.agents.pay', $a->id) }}" onsubmit="return confirm('Mark all commissions for {{ $a->name }} as paid?')">
                            @csrf
                            <button type="submit" class="btn-pay">Mark Paid</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" style="padding:44px 18px;text-align:center;color:var(--muted)">No pending withdrawal requests.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        {{ $withdrawals->links() }}
    </div>
</div>

@if($paid->count() > 0)
<div class="panel">
    <div class="panel-head">
        <div class="panel-title">Paid Withdrawals</div>
        <div></div>
    </div>
    <div class="table-wrap">
        <table class="data-table">
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
                @php ($a = $wd->agent) @endphp
                <tr>
                    <td>
                        <div class="agent-cell">
                            <div class="agent-avatar">{{ strtoupper(substr(($a->name ?? '?'), 0, 1)) }}</div>
                            <div class="agent-name">{{ $a->name ?? 'Unknown' }}</div>
                        </div>
                    </td>
                    <td style="font-size:12px;color:#30465b">
                        @if($a && ($a->bank_name || $a->account_number))
                            {{ $a->bank_name }} &middot; {{ $a->account_number }}
                        @else
                            <span style="color:var(--muted)">Not provided</span>
                        @endif
                    </td>
                    <td class="num"><strong>₦{{ number_format(abs($wd->commission_amount), 2) }}</strong></td>
                    <td class="num" style="color:var(--muted)">{{ $wd->created_at->format('d M Y') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="panel-footer">
        {{ $paid->links() }}
    </div>
</div>
@endif
@endsection
