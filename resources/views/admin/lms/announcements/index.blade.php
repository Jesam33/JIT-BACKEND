@extends('admin.lms.layout')

@section('title', 'Platform Announcements')
@section('toolbar_title', 'Platform Announcements')
@section('toolbar_text', 'Broadcast a message to everybody — students, staff and agents — across every institute on the platform.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('content')
    @if(session('status'))
        <div class="alert ok" style="padding:12px 14px;background:#ecfdf5;border:1px solid #bbf7d0;color:#14532d;border-radius:12px">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert error" style="padding:12px 14px;background:#fff7f7;border:1px solid #fecaca;color:#7f1d1d;border-radius:12px">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert error" style="padding:12px 14px;background:#fff7f7;border:1px solid #fecaca;color:#7f1d1d;border-radius:12px">
            <ul style="margin:0;padding-left:18px">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">New Announcement</div>
            <div></div>
        </div>
        <div style="padding:18px">
            <form method="POST" action="{{ route('admin.lms.announcements.store') }}">
                @csrf

                <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Title</label>
                <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required
                       placeholder="e.g. Scheduled maintenance this weekend"
                       style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:16px">

                <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Message</label>
                <textarea name="body" rows="5" maxlength="5000" required
                          placeholder="Write the announcement everyone will receive as a notification and an email."
                          style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;margin-bottom:16px">{{ old('body') }}</textarea>

                <label style="display:block;margin-bottom:8px;font-size:13px;font-weight:700;color:#30465b">Send to</label>
                @php $oldAudiences = old('audiences', $audienceOptions); @endphp
                <div style="display:flex;gap:18px;flex-wrap:wrap;margin-bottom:20px">
                    @foreach($audienceOptions as $audience)
                        <label style="display:inline-flex;align-items:center;gap:8px;font-size:14px;text-transform:capitalize;cursor:pointer">
                            <input type="checkbox" name="audiences[]" value="{{ $audience }}"
                                   {{ in_array($audience, $oldAudiences) ? 'checked' : '' }}>
                            {{ $audience }}s
                        </label>
                    @endforeach
                </div>

                <div style="display:flex;align-items:center;gap:12px">
                    <button type="submit" class="action primary" style="padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer">Send Announcement</button>
                    <span style="font-size:12px;color:var(--muted)">Delivered as an in-app notification and an email to every recipient, on every institute.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Recent Announcements</div>
            <div></div>
        </div>
        <div style="padding:16px">
            @if(count($announcements ?? []) === 0)
                <div class="panel-empty">No announcements sent yet.</div>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse">
                        <thead>
                            <tr style="text-align:left">
                                <th style="padding:8px;border-bottom:1px solid #e6eef6;font-size:12px;color:#4f6478">Title</th>
                                <th style="padding:8px;border-bottom:1px solid #e6eef6;font-size:12px;color:#4f6478">Audiences</th>
                                <th style="padding:8px;border-bottom:1px solid #e6eef6;font-size:12px;color:#4f6478">Status</th>
                                <th style="padding:8px;border-bottom:1px solid #e6eef6;font-size:12px;color:#4f6478">Recipients</th>
                                <th style="padding:8px;border-bottom:1px solid #e6eef6;font-size:12px;color:#4f6478">Created</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($announcements as $a)
                                @php
                                    $stateClass = match($a->status) {
                                        'dispatched' => 'active',
                                        'failed' => 'danger',
                                        default => 'warning',
                                    };
                                @endphp
                                <tr>
                                    <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">
                                        <div style="font-weight:700;color:#30465b">{{ $a->title }}</div>
                                        <div style="font-size:12px;color:var(--muted);max-width:360px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ $a->body }}</div>
                                    </td>
                                    <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb;text-transform:capitalize">{{ implode(', ', (array) $a->audiences) }}</td>
                                    <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">
                                        <span class="course-state {{ $stateClass }}">{{ ucfirst($a->status) }}</span>
                                        @if($a->status === 'failed' && $a->error)
                                            <div style="font-size:11px;color:var(--danger);margin-top:4px;max-width:260px">{{ $a->error }}</div>
                                        @endif
                                    </td>
                                    <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb">{{ $a->recipients_count }}</td>
                                    <td style="padding:10px 8px;border-bottom:1px solid #f1f6fb;font-size:13px;color:var(--muted)">{{ $a->created_at }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
