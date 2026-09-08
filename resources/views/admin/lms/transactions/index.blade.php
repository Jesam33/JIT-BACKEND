@extends('admin.lms.layout')

@section('title', 'Transactions & Revenue')
@php ($activeLmsPage = 'transactions') @endphp

@section('toolbar_title', 'Transactions & Revenue')
@section('toolbar_text', 'Every institute to platform subscription payment for reconciling against Paystack, plus live per-institute course earnings and the platform commission owed on them.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('head')
    <style>
        .rev-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: 16px;
        }

        .rev-card {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 18px;
        }

        .rev-label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #4f6478;
            font-weight: 700;
            margin-bottom: 12px;
        }

        .rev-label svg {
            width: 15px;
            height: 15px;
            stroke: var(--accent);
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
            flex: none;
        }

        .rev-value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            color: var(--ink);
            margin-bottom: 14px;
            font-variant-numeric: tabular-nums;
        }

        .rev-sub {
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.6;
            margin: 0;
            padding-top: 12px;
            border-top: 1px solid var(--line);
        }

        .rev-note {
            margin: 0;
            font-size: 12px;
            color: var(--muted);
            line-height: 1.6;
        }

        .num-cell {
            text-align: right;
            white-space: nowrap;
        }

        .ref-cell {
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
            <div class="rev-label">
                <svg viewBox="0 0 24 24"><polyline points="22 7 13.5 15.5 8.5 10.5 2 17"/><polyline points="16 7 22 7 22 13"/></svg>
                Platform Revenue (confirmed)
            </div>
            <div class="rev-value">₦{{ number_format($platformRevenue, 2) }}</div>
            <p class="rev-sub">{{ $successCount }} successful subscription payment{{ $successCount === 1 ? '' : 's' }}, reconcile against Paystack</p>
        </div>
        <div class="rev-card">
            <div class="rev-label">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                Pending / Awaiting
            </div>
            <div class="rev-value">₦{{ number_format($pendingPlatform, 2) }}</div>
            <p class="rev-sub">Initialised but not yet confirmed</p>
        </div>
        <div class="rev-card">
            <div class="rev-label">
                <svg viewBox="0 0 24 24"><line x1="19" y1="5" x2="5" y2="19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/></svg>
                Commission Owed (course sales)
            </div>
            <div class="rev-value">₦{{ number_format($totalCommission, 2) }}</div>
            <p class="rev-sub">Platform cut of ₦{{ number_format($totalCourseGross, 2) }} in institute course sales</p>
        </div>
    </div>

    {{-- Platform subscription transactions --}}
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Subscription Payments (institute to platform)</div>
            <div></div>
        </div>
        @if(count($transactions ?? []) === 0)
            <div class="panel-empty">No subscription payments recorded yet.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Institute</th>
                            <th>Plan</th>
                            <th>Purpose</th>
                            <th>Reference</th>
                            <th class="num-cell">Amount</th>
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
                                <td><strong>{{ $t['tenant_name'] }}</strong></td>
                                <td style="text-transform:capitalize">{{ $t['plan'] ?? 'N/A' }}</td>
                                <td>{{ $t['purpose'] === 'tenant_signup' ? 'Signup' : 'Upgrade' }}</td>
                                <td class="ref-cell">{{ $t['reference'] }}</td>
                                <td class="num-cell">{{ $t['currency'] }} {{ number_format($t['amount'], 2) }}</td>
                                <td><span class="course-state {{ $stateClass }}">{{ ucfirst($t['status']) }}</span></td>
                                <td class="row-sub">{{ $t['paid_at'] ? \Illuminate\Support\Carbon::parse($t['paid_at'])->format('d M Y, H:i') : 'N/A' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Ledger 2: per-institute course earnings + commission (live) --}}
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Institute Course Earnings &amp; Commission (live)</div>
            <div></div>
        </div>
        @if(count($instituteEarnings ?? []) === 0)
            <div class="panel-empty">No confirmed course sales yet.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Institute</th>
                            <th>Plan</th>
                            <th class="num-cell">Sales</th>
                            <th class="num-cell">Gross earned</th>
                            <th class="num-cell">Commission %</th>
                            <th class="num-cell">Platform commission</th>
                            <th class="num-cell">Net to institute</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($instituteEarnings as $e)
                            <tr>
                                <td><strong>{{ $e['tenant_name'] }}</strong></td>
                                <td style="text-transform:capitalize">{{ $e['plan'] }}</td>
                                <td class="num-cell">{{ $e['sales'] }}</td>
                                <td class="num-cell">₦{{ number_format($e['gross'], 2) }}</td>
                                <td class="num-cell">{{ rtrim(rtrim(number_format($e['commission_percent'], 2), '0'), '.') }}%</td>
                                <td class="num-cell">₦{{ number_format($e['commission'], 2) }}</td>
                                <td class="num-cell">₦{{ number_format($e['net_to_institute'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="3">Totals</td>
                            <td class="num-cell">₦{{ number_format($totalCourseGross, 2) }}</td>
                            <td class="num-cell">N/A</td>
                            <td class="num-cell">₦{{ number_format($totalCommission, 2) }}</td>
                            <td class="num-cell">₦{{ number_format($totalCourseGross - $totalCommission, 2) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="panel-body">
                <p class="rev-note">Course earnings are derived live from successful student payments; the platform commission uses each institute's own plan rate. Numbers reflect funds as they stand right now.</p>
            </div>
        @endif
    </div>
@endsection
