@extends('admin.lms.layout')

@section('title', 'Registered Institutes')
@php ($activeLmsPage = 'institutes') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); margin-bottom: 14px; }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); margin-bottom: 14px; }
        .institute-cell strong { font-size: 14px; }
        .institute-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
        .institute-actions form { display: inline; }
        .institute-actions .chip, .institute-actions .action { cursor: pointer; }
        .action.danger-ghost { color: var(--danger); border-color: rgba(185, 28, 28, 0.28); }
        .action.danger-ghost:hover { border-color: var(--danger); background: var(--danger-soft); }
        .delete-confirm { display: none; margin-top: 10px; padding: 12px; border-radius: 10px; background: var(--danger-soft); border: 1px solid rgba(185, 28, 28, 0.22); }
        .delete-confirm-text { font-size: 12.5px; line-height: 1.5; margin-bottom: 10px; color: var(--danger); }
        .delete-confirm-label { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 10px; font-size: 12.5px; line-height: 1.5; color: #6b4550; cursor: pointer; }
        .delete-confirm-label input[type="checkbox"] { width: 15px; height: 15px; margin-top: 2px; accent-color: var(--danger); cursor: pointer; }
        .delete-confirm-actions { display: flex; gap: 8px; }
        .btn-confirm-danger { background: var(--danger); border: 1px solid var(--danger); color: #fff; border-radius: 10px; padding: 7px 12px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; transition: background 0.15s ease, border-color 0.15s ease; }
        .btn-confirm-danger:hover:not(:disabled) { background: #991b1b; border-color: #991b1b; }
        .btn-confirm-danger:disabled { opacity: 0.45; cursor: not-allowed; }
        .btn-confirm-cancel { background: #fff; border: 1px solid var(--line); color: var(--ink); border-radius: 10px; padding: 7px 12px; font: inherit; font-size: 12px; font-weight: 700; cursor: pointer; transition: border-color 0.15s ease; }
        .btn-confirm-cancel:hover { border-color: #cfd8e2; }
        .lifecycle-badge { display: inline-block; margin-top: 4px; padding: 3px 8px; border-radius: 999px; font-size: 11px; font-weight: 700; }
        .lifecycle-badge.off { background: var(--warning-soft, #fdf3d7); color: #8a5a00; }
        .lifecycle-badge.doomed { background: var(--danger-soft); color: var(--danger); }
        .lifecycle-badge.gone { background: #eceff3; color: #5a6472; }
        .lifecycle-note { margin-top: 4px; font-size: 11.5px; line-height: 1.45; color: #6b7280; }
    </style>
@endsection

@section('toolbar_title', 'Registered Institutes')
@section('toolbar_text', 'Every tenant institute registered on the platform, with onboarding resend, availability and closure controls.')

@section('toolbar_actions')
    <div class="chip">Institutes <strong>{{ count($tenantsList ?? []) }}</strong></div>
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('content')
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Institutes</div>
        </div>
        <div class="panel-body">
            @if(session('status'))
                <div class="flash-ok">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="flash-error">{{ session('error') }}</div>
            @endif
            @if(count($tenantsList ?? []) === 0)
                <div class="panel-empty">No institutes found.</div>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Slug</th>
                                <th>Owner</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($tenantsList as $t)
                                @php
                                    $lifecycle = $t['lifecycle'] ?? 'active';
                                    $isGone = $lifecycle === 'purged';
                                    $isDoomed = $lifecycle === 'purge_scheduled';
                                    $isOff = $lifecycle === 'deactivated';
                                @endphp
                                <tr>
                                    <td class="institute-cell"><strong>{{ $t['name'] }}</strong></td>
                                    <td>{{ $t['slug'] }}</td>
                                    <td>{{ $t['owner_email'] ?? 'Not set' }}</td>
                                    <td>
                                        <span class="course-state {{ ($t['status'] ?? 'active') === 'active' ? 'active' : 'warning' }}">{{ $t['status'] ?? 'active' }}</span>
                                        {{-- Availability is a SEPARATE axis from provisioning status,
                                             so it gets its own badge rather than overwriting it. --}}
                                        @if($isDoomed)
                                            <div><span class="lifecycle-badge doomed">Closing {{ \Illuminate\Support\Carbon::parse($t['purge_after'])->format('j M Y') }}</span></div>
                                        @elseif($isGone)
                                            <div><span class="lifecycle-badge gone">Closed</span></div>
                                        @elseif($isOff)
                                            <div><span class="lifecycle-badge off">Offline</span></div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $t['created_at'] }}</td>
                                    <td>
                                        <div class="institute-actions">
                                            @if($isGone)
                                                <span class="lifecycle-note">This institute has been closed. Nothing further can be changed here.</span>
                                            @else
                                                <form method="POST" action="{{ route('admin.lms.institutes.resend', $t['id']) }}">
                                                    @csrf
                                                    <button type="submit" class="chip">Resend Onboarding</button>
                                                </form>

                                                @if($isOff || $isDoomed)
                                                    <form method="POST" action="{{ route('admin.lms.institutes.reactivate', $t['id']) }}">
                                                        @csrf
                                                        <button type="submit" class="chip">{{ $isDoomed ? 'Cancel Closure' : 'Bring Online' }}</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('admin.lms.institutes.deactivate', $t['id']) }}">
                                                        @csrf
                                                        <button type="submit" class="chip">Take Offline</button>
                                                    </form>
                                                @endif

                                                @unless($isDoomed)
                                                    <form method="POST" action="{{ route('admin.lms.institutes.delete', $t['id']) }}" id="delete-form-{{ $t['id'] }}">
                                                        @csrf
                                                        <button type="button" class="action danger-ghost" onclick="showDeleteConfirm({{ $t['id'] }})">Delete</button>

                                                        <div class="delete-confirm" id="delete-confirm-{{ $t['id'] }}">
                                                            <div class="delete-confirm-text">
                                                                <strong>{{ $t['name'] }}</strong> goes offline straight away and is permanently closed in
                                                                {{ $defaultPurgeWindowDays ?? 30 }} days. Until then every student, course, enrolment,
                                                                payment and certificate it holds is left exactly as it is, and you can cancel this from
                                                                this page. The institute's owner account is not removed during the window.
                                                            </div>
                                                            <label class="delete-confirm-label"><input type="checkbox" id="delete-confirm-check-{{ $t['id'] }}"> I understand this institute will be permanently closed once the window ends</label>
                                                            <div class="delete-confirm-actions">
                                                                <button type="submit" id="delete-confirm-btn-{{ $t['id'] }}" class="btn-confirm-danger" disabled>Schedule Closure</button>
                                                                <button type="button" onclick="hideDeleteConfirm({{ $t['id'] }})" class="btn-confirm-cancel">Cancel</button>
                                                            </div>
                                                        </div>
                                                    </form>
                                                @endunless
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection

@push('scripts')
<script>
function showDeleteConfirm(id) {
    var el = document.getElementById('delete-confirm-' + id);
    if (!el) return;
    el.style.display = 'block';
    var chk = document.getElementById('delete-confirm-check-' + id);
    var btn = document.getElementById('delete-confirm-btn-' + id);
    if (chk && btn) {
        chk.checked = false;
        btn.disabled = true;
        chk.addEventListener('change', function() {
            btn.disabled = !chk.checked;
        });
    }
}

function hideDeleteConfirm(id) {
    var el = document.getElementById('delete-confirm-' + id);
    if (!el) return;
    el.style.display = 'none';
}
</script>
@endpush
