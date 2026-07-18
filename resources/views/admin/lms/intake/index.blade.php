@extends('admin.lms.layout')

@section('title', 'LMS Intake & Approvals')
@php($activeLmsPage = 'registrations')

@section('head')
    <style>
        .stack {
            display: grid;
            gap: 16px;
        }

        .alert {
            border-radius: 12px;
            padding: 12px 14px;
            font-size: 14px;
            border: 1px solid transparent;
        }

        .alert.ok {
            background: var(--success-soft);
            border-color: rgba(31, 157, 85, 0.16);
            color: var(--success);
        }

        .alert.warn {
            background: var(--danger-soft);
            border-color: rgba(185, 28, 28, 0.16);
            color: var(--danger);
        }

        .alert.note {
            background: #f5f8fb;
            border-color: var(--line);
            color: #385066;
        }

        .summary-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 16px;
        }

        .summary-card {
            padding: 16px;
        }

        .summary-card h3 {
            margin: 0 0 8px;
            color: #4f6478;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }

        .summary-card strong {
            display: block;
            font-size: 34px;
            letter-spacing: -0.04em;
            margin-bottom: 8px;
        }

        .summary-card p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        .table-wrap {
            overflow: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            text-align: left;
            border-bottom: 1px solid var(--line);
            padding: 12px 10px;
            font-size: 14px;
            vertical-align: top;
        }

        th {
            background: #fbfdff;
            color: #627a8f;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 800;
        }

        .status {
            display: inline-flex;
            align-items: center;
            padding: 4px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 700;
        }

        .pending { background: rgba(217, 119, 6, 0.12); color: #b45309; }
        .approved { background: rgba(31, 157, 85, 0.10); color: #1f9d55; }
        .rejected { background: rgba(185, 28, 28, 0.12); color: #b91c1c; }

        .small { font-size: 12px; color: var(--muted); margin-top: 4px; }

        .price-input {
            width: 120px;
            padding: 9px 10px;
            border: 1px solid #d7dfe8;
            border-radius: 8px;
            margin-bottom: 8px;
            font: inherit;
        }

        .btn,
        .btn-secondary,
        .btn-danger {
            border: 0;
            border-radius: 8px;
            padding: 9px 12px;
            font-weight: 700;
            cursor: pointer;
            font: inherit;
        }

        .btn { background: var(--accent); color: #fff; }
        .btn[disabled] { opacity: 0.45; cursor: not-allowed; }
        .btn-secondary { background: #6b7d90; color: #fff; }
        .btn-danger { background: #8a1515; color: #fff; }
        .action-row,
        .action-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }

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
                <h3>Pending approvals</h3>
                <strong>{{ $items->where('status', 'pending')->count() }}</strong>
                <p>Applicants waiting for admin review before they can activate a student account.</p>
            </article>

            <article class="summary-card panel">
                <h3>Approved intake</h3>
                <strong>{{ $items->where('status', 'approved')->count() }}</strong>
                <p>Approved learners who are ready to enter onboarding and course selection inside the LMS.</p>
            </article>

            <article class="summary-card panel">
                <h3>Rejected intake</h3>
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
                <table>
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
                                <td>{{ $item->id }}</td>
                                <td>{{ $item->first_name }} {{ $item->last_name }}</td>
                                <td>{{ $item->email }}</td>
                                <td>{{ $item->course_name }}</td>
                                <td>{{ $item->course_price ? '$' . number_format((float) $item->course_price, 2) : '-' }}</td>
                                <td>{{ $item->learning_mode }}</td>
                                <td>
                                    <span class="status {{ $item->status === 'approved' ? 'approved' : ($item->status === 'rejected' ? 'rejected' : 'pending') }}">{{ $item->status }}</span>
                                </td>
                                <td>
                                    {{ optional($item->created_at)->format('Y-m-d H:i') }}
                                    <div class="small">{{ $item->phone_number }}</div>
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
                                <td colspan="9">No registrations found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
@endsection
