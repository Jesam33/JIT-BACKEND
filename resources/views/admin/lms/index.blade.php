@extends('admin.lms.layout')

@section('title', 'Platform Overview')
@php ($activeLmsPage = 'dashboard') @endphp

@section('head')
    <style>
        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
        }

        .stat-card {
            padding: 18px;
            display: grid;
            gap: 14px;
        }

        .stat-top {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
        }

        .stat-label {
            color: #4f6478;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .stat-link {
            color: var(--accent);
            font-size: 11px;
            text-decoration: none;
            font-weight: 700;
            white-space: nowrap;
        }

        .stat-body {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
        }

        .stat-value {
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
            letter-spacing: -0.03em;
            white-space: nowrap;
        }

        .delta {
            border-radius: 8px;
            padding: 6px 8px;
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .delta.up {
            background: var(--success-soft);
            color: var(--success);
        }

        .delta.down {
            background: var(--accent-soft);
            color: var(--accent);
        }

        .delta.flat {
            background: #eef2f6;
            color: #5c7286;
        }

        .stat-meta {
            display: flex;
            gap: 24px;
            color: #7d8fa0;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-weight: 700;
            border-top: 1px solid var(--line);
            padding-top: 12px;
        }

        .stat-meta span {
            display: block;
            margin-top: 4px;
            color: var(--ink);
            font-size: 17px;
            letter-spacing: -0.02em;
        }

        .main-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.6fr) minmax(300px, 0.8fr);
            gap: 16px;
        }

        .split-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
        }

        .chart-wrap {
            padding: 16px;
        }

        .chart-shell {
            position: relative;
            height: 330px;
        }

        .chart-svg {
            width: 100%;
            height: 270px;
            display: block;
        }

        .chart-grid line {
            stroke: #ebf0f4;
            stroke-width: 1;
        }

        .chart-grid text,
        .chart-x text {
            fill: #8fa0af;
            font-size: 11px;
            font-family: inherit;
        }

        .chart-area {
            fill: url(#salesFill);
        }

        .chart-line {
            fill: none;
            stroke: var(--accent);
            stroke-width: 2.5;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .chart-point {
            fill: #fff;
            stroke: var(--accent);
            stroke-width: 2.5;
        }

        .chart-summary {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            margin-top: 10px;
            color: #5c7286;
            font-size: 12px;
            font-weight: 700;
        }

        .chart-summary span b {
            color: var(--ink);
            margin-left: 4px;
            font-size: 18px;
            letter-spacing: -0.03em;
        }

        /* Attention center: every row is one link, one decision. */
        .action-rows {
            display: grid;
        }

        .action-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 16px;
            border-bottom: 1px solid var(--line);
            text-decoration: none;
            color: inherit;
        }

        .action-row:last-child {
            border-bottom: 0;
        }

        .action-row:hover {
            background: #fbfdff;
        }

        .action-copy strong {
            display: block;
            font-size: 13px;
        }

        .action-copy span {
            display: block;
            margin-top: 2px;
            color: var(--muted);
            font-size: 12px;
            line-height: 1.45;
        }

        .action-count {
            min-width: 34px;
            text-align: center;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 13px;
            font-weight: 800;
            background: var(--accent-soft);
            color: var(--accent);
            white-space: nowrap;
        }

        .action-count.zero {
            background: var(--success-soft);
            color: var(--success);
        }

        /* Agent economy split tiles: Jorsas agents vs every other academy. */
        .agent-split {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0;
            border-bottom: 1px solid var(--line);
        }

        .agent-tile {
            padding: 16px;
        }

        .agent-tile + .agent-tile {
            border-left: 1px solid var(--line);
        }

        .agent-tile-label {
            color: #4f6478;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .agent-tile-value {
            margin-top: 8px;
            font-size: 24px;
            font-weight: 800;
            letter-spacing: -0.04em;
        }

        .agent-tile-meta {
            margin-top: 4px;
            color: var(--muted);
            font-size: 12px;
        }

        @media (max-width: 1180px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .main-grid,
            .split-grid {
                grid-template-columns: 1fr;
            }

            .agent-split {
                grid-template-columns: 1fr;
            }

            .agent-tile + .agent-tile {
                border-left: 0;
                border-top: 1px solid var(--line);
            }
        }

        @media (max-width: 720px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .stat-body {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
@endsection

@section('toolbar_title', 'Platform Overview')
@section('toolbar_text', 'Your host view of every Online Academy on Jorsastech: money in, money owed, agent earnings and what needs your attention.')

@section('toolbar_actions')
    <div class="chip">Academies <strong>{{ number_format($tenantCount) }}</strong></div>
    <div class="chip">Students <strong>{{ number_format($studentCount) }}</strong></div>
    <div class="chip">New this month <strong>{{ number_format($newTenantsThisMonth) }}</strong></div>
    <a href="{{ route('admin.lms.transactions.index') }}" class="action primary">Revenue detail</a>
@endsection

@section('content')
    @php
        // Pill mappings reusing the layout's .course-state styles.
        $planStates = ['free' => 'inactive', 'basic' => 'warning', 'pro' => 'active', 'enterprise' => 'danger'];
        $subStates = ['active' => 'active', 'grace' => 'warning', 'frozen' => 'danger'];
        $agentStates = ['approved' => 'active', 'pending' => 'warning'];

        $agentTotals = [
            'agents' => $agentRows->sum('agents'),
            'deals' => $agentRows->sum('deals'),
            'earned' => $agentRows->sum('earned'),
            'pending_payout' => $agentRows->sum('pending_payout'),
            'paid_out' => $agentRows->sum('paid_out'),
            'balance' => $agentRows->sum('balance'),
        ];
    @endphp

    <section class="stats">
        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Platform revenue</div>
                <a href="{{ route('admin.lms.transactions.index') }}" class="stat-link">Transactions</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">₦{{ number_format($platformRevenue) }}</div>
                <div class="delta up">{{ number_format($platformTxCount) }} payments</div>
            </div>
            <div class="stat-meta">
                <div>Successful<span>{{ number_format($platformTxCount) }}</span></div>
                <div>Pending<span>₦{{ number_format($pendingPlatform) }}</span></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Course sales, all academies</div>
                <a href="{{ route('admin.lms.transactions.index') }}" class="stat-link">Sales ledger</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">₦{{ number_format($courseGross) }}</div>
                <div class="delta up">{{ number_format($salesCount) }} sales</div>
            </div>
            <div class="stat-meta">
                <div>This month<span>₦{{ number_format($salesThisMonth) }}</span></div>
                <div>Selling academies<span>{{ number_format($sellingAcademies) }}</span></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Commission earned</div>
                <a href="{{ route('admin.lms.institutes.index') }}" class="stat-link">Academies</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">₦{{ number_format($totalCommission) }}</div>
                <div class="delta flat">≈ {{ $courseGross > 0 ? round(($totalCommission / $courseGross) * 100, 1) : 0 }}% of sales</div>
            </div>
            <div class="stat-meta">
                <div>Lifetime sales<span>₦{{ number_format($courseGross) }}</span></div>
                <div>Academies<span>{{ number_format($tenantCount) }}</span></div>
            </div>
        </article>

        <article class="stat-card">
            <div class="stat-top">
                <div class="stat-label">Agent commissions</div>
                <a href="{{ route('admin.lms.agents.withdrawals') }}" class="stat-link">Payouts</a>
            </div>
            <div class="stat-body">
                <div class="stat-value">₦{{ number_format($agentEarnedTotal) }}</div>
                <div class="delta {{ $agentPayoutPending > 0 ? 'down' : 'up' }}">₦{{ number_format($agentPayoutPending) }} to pay</div>
            </div>
            <div class="stat-meta">
                <div>Agents<span>{{ number_format($agentCount) }}</span></div>
                <div>Payout requests<span>{{ number_format($withdrawalRequests) }}</span></div>
            </div>
        </article>
    </section>

    <section class="main-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Course sales across all academies</div>
                <a href="{{ route('admin.lms.transactions.index') }}" class="panel-link">Transactions</a>
            </div>
            <div class="chart-wrap">
                <div class="chart-shell">
                    <svg class="chart-svg" viewBox="0 0 {{ $chartWidth }} {{ $chartHeight }}" preserveAspectRatio="none" role="img" aria-label="Monthly course sales chart">
                        <defs>
                            <linearGradient id="salesFill" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#ed180d" stop-opacity="0.20"></stop>
                                <stop offset="100%" stop-color="#ed180d" stop-opacity="0.02"></stop>
                            </linearGradient>
                        </defs>

                        <g class="chart-grid">
                            @foreach ($chartTicks as $tick)
                                <line x1="0" y1="{{ $tick['y'] }}" x2="{{ $chartWidth }}" y2="{{ $tick['y'] }}"></line>
                                <text x="0" y="{{ $tick['y'] - 6 }}">₦{{ $tick['value'] }}</text>
                            @endforeach
                        </g>

                        <polygon class="chart-area" points="{{ $chartAreaPoints }}"></polygon>
                        <polyline class="chart-line" points="{{ $chartLinePoints }}"></polyline>

                        @foreach ($chartPoints as $point)
                            <circle class="chart-point" cx="{{ $point['x'] }}" cy="{{ $point['y'] }}" r="5"></circle>
                        @endforeach

                        <g class="chart-x">
                            @foreach ($chartPoints as $point)
                                <text x="{{ $point['x'] }}" y="{{ $chartBaseline + 18 }}" text-anchor="middle">{{ $point['label'] }}</text>
                            @endforeach
                        </g>
                    </svg>
                </div>

                <div class="chart-summary">
                    <span>Academies<b>{{ number_format($tenantCount) }}</b></span>
                    <span>Students<b>{{ number_format($studentCount) }}</b></span>
                    <span>Staff<b>{{ number_format($teachers) }}</b></span>
                    <span>Courses<b>{{ number_format($courses) }}</b></span>
                    <span>Cohorts<b>{{ number_format($tracks) }}</b></span>
                </div>
            </div>
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Needs your attention</div>
            </div>

            <div class="action-rows">
                @foreach ($attention as $item)
                    <a class="action-row" href="{{ $item['href'] }}">
                        <div class="action-copy">
                            <strong>{{ $item['label'] }}</strong>
                            <span>{{ $item['detail'] }}</span>
                        </div>
                        <span class="action-count {{ $item['count'] > 0 ? '' : 'zero' }}">{{ number_format($item['count']) }}</span>
                    </a>
                @endforeach
            </div>
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Agent earnings by academy</div>
            <a href="{{ route('admin.lms.agents.withdrawals') }}" class="panel-link">Payout requests</a>
        </div>

        @if ($agentRows->isEmpty())
            <div class="panel-empty">No agents have been approved or earned commissions yet. Agent activity across every academy will appear here.</div>
        @else
            <div class="agent-split">
                <div class="agent-tile">
                    <div class="agent-tile-label">Jorsas agents (primary institute)</div>
                    <div class="agent-tile-value">₦{{ number_format($jorsasAgents['earned']) }}</div>
                    <div class="agent-tile-meta">{{ number_format($jorsasAgents['agents']) }} agents · ₦{{ number_format($jorsasAgents['pending_payout']) }} awaiting payout</div>
                </div>
                <div class="agent-tile">
                    <div class="agent-tile-label">Agents of other academies</div>
                    <div class="agent-tile-value">₦{{ number_format($otherAgents['earned']) }}</div>
                    <div class="agent-tile-meta">{{ number_format($otherAgents['agents']) }} agents · ₦{{ number_format($otherAgents['pending_payout']) }} awaiting payout</div>
                </div>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Academy</th>
                            <th class="num">Agents</th>
                            <th class="num">Deals</th>
                            <th class="num">Earned</th>
                            <th class="num">Awaiting payout</th>
                            <th class="num">Paid out</th>
                            <th class="num">Agent balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($agentRows as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row['name'] }}</strong>
                                    @if ($row['is_primary'])
                                        <div class="row-sub">Primary institute</div>
                                    @endif
                                </td>
                                <td class="num">{{ number_format($row['agents']) }}</td>
                                <td class="num">{{ number_format($row['deals']) }}</td>
                                <td class="num">₦{{ number_format($row['earned']) }}</td>
                                <td class="num">₦{{ number_format($row['pending_payout']) }}</td>
                                <td class="num">₦{{ number_format($row['paid_out']) }}</td>
                                <td class="num"><strong>₦{{ number_format($row['balance']) }}</strong></td>
                            </tr>
                        @endforeach
                        <tr class="total-row">
                            <td>Total</td>
                            <td class="num">{{ number_format($agentTotals['agents']) }}</td>
                            <td class="num">{{ number_format($agentTotals['deals']) }}</td>
                            <td class="num">₦{{ number_format($agentTotals['earned']) }}</td>
                            <td class="num">₦{{ number_format($agentTotals['pending_payout']) }}</td>
                            <td class="num">₦{{ number_format($agentTotals['paid_out']) }}</td>
                            <td class="num">₦{{ number_format($agentTotals['balance']) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="split-grid">
        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Top agents, all academies</div>
                <a href="{{ route('admin.lms.agents.index') }}" class="panel-link">All agents</a>
            </div>

            @if ($topAgents->isEmpty())
                <div class="panel-empty">No agent commissions have been recorded yet.</div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Agent</th>
                            <th>Academy</th>
                            <th class="num">Deals</th>
                            <th class="num">Earned</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($topAgents as $agent)
                            <tr>
                                <td>
                                    <strong>{{ $agent['name'] }}</strong>
                                    <div class="course-state {{ $agentStates[$agent['status']] ?? 'danger' }}" style="margin-top: 6px; width: fit-content;">
                                        {{ ucfirst($agent['status']) }}
                                    </div>
                                </td>
                                <td>{{ $agent['academy'] }}</td>
                                <td class="num">{{ number_format($agent['deals']) }}</td>
                                <td class="num"><strong>₦{{ number_format($agent['earned']) }}</strong></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </article>

        <article class="panel">
            <div class="panel-head">
                <div class="panel-title">Newest academies</div>
                <a href="{{ route('admin.lms.institutes.index') }}" class="panel-link">All institutes</a>
            </div>

            @if ($newestAcademies->isEmpty())
                <div class="panel-empty">No academies have signed up yet.</div>
            @else
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Academy</th>
                            <th>Plan</th>
                            <th>Owner</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($newestAcademies as $academy)
                            <tr>
                                <td>
                                    <strong>{{ $academy['name'] }}</strong>
                                    <div class="course-state {{ $subStates[$academy['state']] ?? 'danger' }}" style="margin-top: 6px; width: fit-content;">
                                        {{ ucfirst($academy['state']) }}
                                    </div>
                                </td>
                                <td>
                                    <div class="course-state {{ $planStates[$academy['plan']] ?? 'inactive' }}" style="width: fit-content;">
                                        {{ ucfirst($academy['plan']) }}
                                    </div>
                                </td>
                                <td>{{ $academy['owner_email'] }}</td>
                                <td>{{ optional($academy['created_at'])->format('d-m-Y') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </article>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title">Academies, ranked by course sales</div>
            <a href="{{ route('admin.lms.institutes.index') }}" class="panel-link">All institutes</a>
        </div>

        @if ($academyRows->isEmpty())
            <div class="panel-empty">No academies exist yet. They will appear here the moment they sign up.</div>
        @else
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Academy</th>
                            <th>Plan</th>
                            <th>State</th>
                            <th class="num">Students</th>
                            <th class="num">Courses</th>
                            <th class="num">Sales</th>
                            <th class="num">Gross sales</th>
                            <th class="num">Commission</th>
                            <th>Joined</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($academyRows->take(12) as $row)
                            <tr>
                                <td>
                                    <strong>{{ $row['name'] }}</strong>
                                    @if ($row['is_primary'])
                                        <div class="row-sub">Primary institute</div>
                                    @endif
                                </td>
                                <td>
                                    <div class="course-state {{ $planStates[$row['plan']] ?? 'inactive' }}" style="width: fit-content;">
                                        {{ ucfirst($row['plan']) }}
                                    </div>
                                </td>
                                <td>
                                    <div class="course-state {{ $subStates[$row['state']] ?? 'danger' }}" style="width: fit-content;">
                                        {{ ucfirst($row['state']) }}
                                    </div>
                                </td>
                                <td class="num">{{ number_format($row['students']) }}</td>
                                <td class="num">{{ number_format($row['courses']) }}</td>
                                <td class="num">{{ number_format($row['sales']) }}</td>
                                <td class="num">₦{{ number_format($row['gross']) }}</td>
                                <td class="num"><strong>₦{{ number_format($row['commission']) }}</strong></td>
                                <td>{{ optional($row['created_at'])->format('d-m-Y') ?? '-' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
@endsection