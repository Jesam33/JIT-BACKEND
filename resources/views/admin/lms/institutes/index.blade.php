@extends('admin.lms.layout')

@section('toolbar_title', 'Registered Institutes')
@section('toolbar_text', 'List of tenant institutes registered on the platform')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('content')
    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Institutes</div>
            <div></div>
        </div>
        <div style="padding:16px;">
            @if(session('status'))
                <div class="alert ok" style="margin-bottom:12px;padding:10px 12px;background:#ecfdf5;border:1px solid #bbf7d0;color:#14532d">{{ session('status') }}</div>
            @endif
            @if(session('error'))
                <div class="alert error" style="margin-bottom:12px;padding:10px 12px;background:#fff7f7;border:1px solid #fecaca;color:#7f1d1d">{{ session('error') }}</div>
            @endif
            @if(count($tenantsList ?? []) === 0)
                <div class="panel-empty">No institutes found.</div>
            @else
                <table style="width:100%;border-collapse:collapse">
                    <thead>
                        <tr style="text-align:left">
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Name</th>
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Slug</th>
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Owner</th>
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Status</th>
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Created</th>
                            <th style="padding:8px;border-bottom:1px solid #e6eef6">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($tenantsList as $t)
                            <tr>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $t['name'] }}</td>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $t['slug'] }}</td>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $t['owner_email'] ?? '—' }}</td>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $t['status'] ?? 'active' }}</td>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $t['created_at'] }}</td>
                                <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">
                                    <div style="display:flex;gap:8px">
                                        <a href="{{ route('admin.lms.institutes.index') }}" class="chip">View</a>

                                        <form method="POST" action="{{ route('admin.lms.institutes.resend', $t['id']) }}" style="display:inline">
                                            @csrf
                                            <button type="submit" class="chip">Resend Onboarding</button>
                                        </form>

                                        <form method="POST" action="{{ route('admin.lms.institutes.delete', $t['id']) }}" style="display:inline" id="delete-form-{{ $t['id'] }}">
                                            @csrf
                                            <button type="button" class="action" style="background:#ef4444;border-color:#ef4444;color:#fff" onclick="showDeleteConfirm({{ $t['id'] }})">Delete</button>

                                            <div id="delete-confirm-{{ $t['id'] }}" style="display:none; margin-top:8px; padding:8px; border:1px solid #fee2e2; background:#fff7f7;">
                                                <div style="font-size:13px; margin-bottom:8px; color:#7f1d1d">Deleting institute <strong>{{ $t['name'] }}</strong> will also remove its owner account if that account is not linked to any other institute.</div>
                                                <label style="display:block; margin-bottom:8px;"><input type="checkbox" id="delete-confirm-check-{{ $t['id'] }}"> I understand and want to delete this institute and its owner account (if applicable)</label>
                                                <div style="display:flex; gap:8px">
                                                    <button type="submit" id="delete-confirm-btn-{{ $t['id'] }}" disabled style="background:#b91c1c;border-color:#b91c1c;color:#fff;padding:6px 10px;border-radius:4px;border:1px solid">Confirm Delete</button>
                                                    <button type="button" onclick="hideDeleteConfirm({{ $t['id'] }})" style="background:#eef2ff;border-color:#eef2ff;color:#1e293b;padding:6px 10px;border-radius:4px;border:1px solid">Cancel</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
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
