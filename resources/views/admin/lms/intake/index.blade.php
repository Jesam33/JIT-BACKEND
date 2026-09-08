@extends('admin.lms.layout')

@section('title', 'LMS Intake & Approvals')
@php ($activeLmsPage = 'registrations') @endphp

@section('head')
    <style>
        .stack {
            display: grid;
            gap: 16px;
        }

        .alert {
            border-radius: 10px;
            padding: 12px 14px;
            font-size: 13px;
            line-height: 1.6;
            border: 1px solid transparent;
        }

        .alert.ok {
            background: var(--success-soft);
            border-color: rgba(18, 145, 90, 0.18);
            color: var(--success);
        }

        .alert.warn {
            background: var(--danger-soft);
            border-color: rgba(185, 28, 28, 0.16);
            color: var(--danger);
        }

        .alert.note {
            background: var(--accent-soft);
            border-color: rgba(237, 24, 13, 0.14);
            color: var(--accent-dark);
        }

        .alert.note a {
            color: var(--accent-dark);
            font-weight: 700;
            word-break: break-all;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .summary-card {
            padding: 18px;
        }

        .summary-card h3 {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 0 0 12px;
            color: #4f6478;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 700;
        }

        .summary-card h3 svg {
            width: 15px;
            height: 15px;
            stroke: var(--accent);
            stroke-width: 1.8;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
            flex: none;
        }

        .summary-card strong {
            display: block;
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 14px;
        }

        .summary-card p {
            margin: 0;
            padding-top: 12px;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: 12.5px;
            line-height: 1.6;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 4px 9px;
            border-radius: 7px;
            font-size: 11px;
            font-weight: 700;
            text-transform: capitalize;
        }

        .pending {
            background: var(--warning-soft);
            color: var(--warning);
        }

        .approved {
            background: var(--success-soft);
            color: var(--success);
        }

        .rejected {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .price-input {
            width: 110px;
            padding: 8px 10px;
            border: 1px solid var(--line);
            border-radius: 10px;
            background: #fff;
            color: var(--ink);
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 8px;
            transition: border-color 0.15s ease;
        }

        .price-input:hover {
            border-color: #cfd8e2;
        }

        .price-input:focus {
            outline: none;
            border-color: var(--accent);
        }

        .btn,
        .btn-secondary,
        .btn-danger {
            border: 1px solid transparent;
            border-radius: 10px;
            padding: 8px 14px;
            font: inherit;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        }

        .btn {
            background: var(--accent);
            color: #fff;
            box-shadow: 0 6px 16px rgba(237, 24, 13, 0.28);
        }

        .btn:hover {
            background: var(--accent-dark);
        }

        .btn[disabled] {
            opacity: 0.45;
            cursor: not-allowed;
            box-shadow: none;
        }

        .btn-secondary {
            background: #fff;
            border-color: var(--line);
            color: #557082;
        }

        .btn-secondary:hover {
            border-color: #cfd8e2;
        }

        .btn-danger {
            background: #fff;
            border-color: var(--line);
            color: var(--danger);
        }

        .btn-danger:hover {
            border-color: var(--danger);
            background: var(--danger-soft);
        }

        .action-row,
        .action-group {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            flex-wrap: wrap;
        }

        .table-empty {
            padding: 24px 14px;
            color: var(--muted);
            font-size: 13px;
        }

        @media (max-width: 1180px) {
            .summary-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
@endsection

@section('toolbar_title', 'Student Intake & Approvals')
@section('toolbar_text', 'Approve applicants, assign pricing, trigger LMS onboarding links, and keep the student intake pipeline inside the LMS workspace.')

@section('toolbar_actions')
    <div class="chip">Pending <strong>{{ $pendingRegistrations }}</strong></div>
    <div class="chip">Applications <strong>{{ $items->count() }}</strong></div>
    <a href="{{ route('admin.lms.index') }}" class="action">Back to dashboard</a>
@endsection

@section('content')
    <div class="stack">
        @if (session('status'))
            <div class="alert ok">{{ session('status') }}</div>
        @endif

        @if (session('email_error'))
            <div class="alert warn">Approval worked, but email send failed: {{ session('email_error') }}</div>
        @endif

        @if (session('mailer') === 'log' || session('mailer') === 'array')
            <div class="alert warn">
                Approval worked, but current mailer is <strong>{{ session('mailer') }}</strong>. This mode does not deliver real inbox emails.
            </div>
        @endif

        @if (session('lms_link'))
            <div class="alert note">
                LMS invite link generated: <a href="{{ session('lms_link') }}" target="_blank" rel="noreferrer">{{ session('lms_link') }}</a>
            </div>
        @endif

        <section class="summary-grid">
            <article class="summary-card panel">
                <h3>
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    Pending approvals
                </h3>
                <strong>{{ $items->where('status', 'pending')->count() }}</strong>
                <p>Applicants waiting for admin review before they can activate a student account.</p>
            </article>

            <article class="summary-card panel">
                <h3>
                    <svg viewBox="0 0 24 24"><path d="M20 6 9 17l-5-5"/></svg>
                    Approved intake
                </h3>
                <strong>{{ $items->where('status', 'approved')->count() }}</strong>
                <p>Approved learners who are ready to enter onboarding and course selection inside the LMS.</p>
            </article>

            <article class="summary-card panel">
                <h3>
                    <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                    Rejected intake
                </h3>
                <strong>{{ $items->where('status', 'rejected')->count() }}</strong>
                <p>Applications that were declined and removed from the current learner intake pipeline.</p>
            </article>
        </section>

        <section class="panel">
            <div class="panel-head">
                <div class="panel-title">Learner application queue</div>
                <a href="{{ route('admin.lms.index') }}" class="panel-link">LMS overview</a>
            </div>

            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Requested Course</th>
                            <th>Price</th>
                            <th>Mode</th>
                            <th>Status</th>
                            <th>Submitted</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($items as $item)
                            <tr>
                                <td class="num">{{ $item->id }}</td>
                                <td>{{ $item->first_name }} {{ $item->last_name }}</td>
                                <td>{{ $item->email }}</td>
                                <td>{{ $item->course_name }}</td>
                                <td class="num">{{ $item->course_price ? '$' . number_format((float) $item->course_price, 2) : 'Not set' }}</td>
                                <td>{{ $item->learning_mode }}</td>
                                <td>
                                    <span class="status {{ $item->status === 'approved' ? 'approved' : ($item->status === 'rejected' ? 'rejected' : 'pending') }}">{{ $item->status }}</span>
                                </td>
                                <td class="num">
                                    {{ optional($item->created_at)->format('Y-m-d H:i') }}
                                    <div class="row-sub">{{ $item->phone_number }}</div>
                                </td>
                                <td>
                                    @if ($item->status === 'pending')
                                        <div class="action-row">
                                            <form method="POST" action="{{ route('admin.lms.intake.approve', $item->id) }}">
                                                @csrf
                                                <input
                                                    class="price-input"
                                                    type="number"
                                                    name="course_price"
                                                    min="0"
                                                    step="0.01"
                                                    placeholder="Price"
                                                    required
                                                >
                                                <button class="btn" type="submit">Approve</button>
                                            </form>

                                            <div class="action-group">
                                                <form method="POST" action="{{ route('admin.lms.intake.decline', $item->id) }}" onsubmit="return confirm('Decline this registration request?');">
                                                    @csrf
                                                    <button class="btn-secondary" type="submit">Decline</button>
                                                </form>

                                                <form method="POST" action="{{ route('admin.lms.intake.delete', $item->id) }}" onsubmit="return confirm('Delete this registration permanently?');">
                                                    @csrf
                                                    <button class="btn-danger" type="submit">Delete</button>
                                                </form>
                                            </div>
                                        </div>
                                    @else
                                        <div class="action-group">
                                            <button class="btn" disabled>{{ $item->status === 'approved' ? 'Approved' : 'Declined' }}</button>
                                            <form method="POST" action="{{ route('admin.lms.intake.delete', $item->id) }}" onsubmit="return confirm('Delete this registration permanently?');">
                                                @csrf
                                                <button class="btn-danger" type="submit">Delete</button>
                                            </form>
                                        </div>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="table-empty">No registrations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
