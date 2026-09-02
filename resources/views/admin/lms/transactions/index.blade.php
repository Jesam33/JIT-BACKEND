@extends('admin.lms.layout')

@section('title', 'Transactions & Revenue')
@section('toolbar_title', 'Transactions & Revenue')
@section('toolbar_text', 'Every institute→platform subscription payment for reconciling against Paystack, plus live per-institute course earnings and the platform commission owed on them.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('head')
<style>
    .rev-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }
    .rev-card {
        background: #fff;
        border: 1px solid var(--line);
        border-radius: 12px;
        box-shadow: var(--shadow);
        padding: 16px 18px;
    }
    .rev-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #4f6478;
        font-weight: 700;
        margin-bottom: 8px;
    }
    .rev-value {
        font-size: 24px;
        font-weight: 800;
        color: #16324f;
    }
    .rev-sub {
        font-size: 12px;
        color: var(--muted);
        margin-top: 4px;
    }
    .rev-table {
        width: 100%;
        border-collapse: collapse;
    }
    .rev-table th {
        padding: 8px;
        border-bottom: 1px solid #e6eef6;
        font-size: 12px;
        color: #4f6478;
        text-align: left;
        white-space: nowrap;
    }
    .rev-table td {
        padding: 10px 8px;
        border-bottom: 1px solid #f1f6fb;
        font-size: 13px;
    }
    .rev-num {
        font-variant-numeric: tabular-nums;
        text-align: right;
        white-space: nowrap;
    }
    .rev-ref {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: 12px;
        color: #4f6478;
    }
</style>
@endsection

@section('content')
    {{-- Ledger 1: platform subscription revenue --}}
    <div class="rev-grid">
        <div class="rev-card">
            <div class="rev-label">Platform Revenue (confirmed)</div>
            <div class="rev-value">₦{{ number_format($platformRevenue, 2) }}</div>
            <div class="rev-sub">{{ $successCount }} successful subscription payment{{ $successCount === 1 ? '' : 's' }} — reconcile against Paystack</div>
        </div>
        <div class="rev-card">
            <div class="rev-label">Pending / Awaiting</div>
            <div class="rev-value">₦{{ number_format($pendingPlatform, 2) }}</div>
            <div class="rev-sub">Initialised but not yet confirmed</div>
        </div>
        <div class="rev-card">
            <div class="rev-label">Commission Owed (course sales)</div>
            <div class="rev-value">₦{{ number_format($totalCommission, 2) }}</div>
            <div class="rev-sub">Platform cut of ₦{{ number_format($totalCourseGross, 2) }} in institute course sales</div>
        </div>
    </div>

    {{-- Platform subscription transactions --}}
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Subscription Payments (institute → platform)</div>
            <div></div>
        </div>
        <div style="padding:16px">
            @if(count($transactions ?? []) === 0)
                <div class="panel-empty">No subscription payments recorded yet.</div>
            @else
                <div style="overflow-x:auto">
                    <table class="rev-table">
                        <thead>
                            <tr>
                                <th>Institute</th>
                                <th>Plan</th>
                                <th>Purpose</th>
                                <th>Reference</th>
                                <th class="rev-num">Amount</th>
                                <th>Status</th>
                                <th>Paid</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($transactions as $t)
                                @php
                                    $stateClass = match($t['status']) {
                                        'success' => 'active',
                                        'failed' => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <tr>
                                    <td style="font-weight:700;color:#30465b">{{ $t['tenant_name'] }}</td>
                                    <td style="text-transform:capitalize">{{ $t['plan'] ?? '—' }}</td>
                                    <td>{{ $t['purpose'] === 'tenant_signup' ? 'Signup' : 'Upgrade' }}</td>
                                    <td class="rev-ref">{{ $t['reference'] }}</td>
                                    <td class="rev-num">{{ $t['currency'] }} {{ number_format($t['amount'], 2) }}</td>
                                    <td><span class="course-state {{ $stateClass }}">{{ ucfirst($t['status']) }}</span></td>
                                    <td style="color:var(--muted)">{{ $t['paid_at'] ? \Illuminate\Support\Carbon::parse($t['paid_at'])->format('d M Y, H:i') : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    {{-- Ledger 2: per-institute course earnings + commission (live) --}}
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Institute Course Earnings & Commission (live)</div>
            <div></div>
        </div>
        <div style="padding:16px">
            @if(count($instituteEarnings ?? []) === 0)
                <div class="panel-empty">No confirmed course sales yet.</div>
            @else
                <div style="overflow-x:auto">
                    <table class="rev-table">
                        <thead>
                            <tr>
                                <th>Institute</th>
                                <th>Plan</th>
                                <th class="rev-num">Sales</th>
                                <th class="rev-num">Gross earned</th>
                                <th class="rev-num">Commission %</th>
                                <th class="rev-num">Platform commission</th>
                                <th class="rev-num">Net to institute</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($instituteEarnings as $e)
                                <tr>
                                    <td style="font-weight:700;color:#30465b">{{ $e['tenant_name'] }}</td>
                                    <td style="text-transform:capitalize">{{ $e['plan'] }}</td>
                                    <td class="rev-num">{{ $e['sales'] }}</td>
                                    <td class="rev-num">₦{{ number_format($e['gross'], 2) }}</td>
                                    <td class="rev-num">{{ rtrim(rtrim(number_format($e['commission_percent'], 2), '0'), '.') }}%</td>
                                    <td class="rev-num">₦{{ number_format($e['commission'], 2) }}</td>
                                    <td class="rev-num">₦{{ number_format($e['net_to_institute'], 2) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr style="font-weight:800;background:var(--panel-alt)">
                                <td colspan="3" style="padding:10px 8px">Totals</td>
                                <td class="rev-num" style="padding:10px 8px">₦{{ number_format($totalCourseGross, 2) }}</td>
                                <td class="rev-num" style="padding:10px 8px">—</td>
                                <td class="rev-num" style="padding:10px 8px">₦{{ number_format($totalCommission, 2) }}</td>
                                <td class="rev-num" style="padding:10px 8px">₦{{ number_format($totalCourseGross - $totalCommission, 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p style="font-size:12px;color:var(--muted);margin:12px 4px 0">
                    Course earnings are derived live from successful student payments; the platform commission uses each institute's own plan rate. Numbers reflect funds as they stand right now.
                </p>
            @endif
        </div>
    </div>
@endsection
