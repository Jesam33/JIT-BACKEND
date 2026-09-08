@extends('admin.lms.layout')

@section('title', 'Platform Announcements')
@php ($activeLmsPage = 'announcements') @endphp

@section('head')
    <style>
        .flash-ok { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--success-soft); color: var(--success); border: 1px solid rgba(18, 145, 90, 0.20); }
        .flash-error { border-radius: 10px; padding: 12px 14px; font-size: 13px; font-weight: 600; background: var(--danger-soft); color: var(--danger); border: 1px solid rgba(185, 28, 28, 0.20); }
        .flash-error ul { margin: 0; padding-left: 18px; }
        .stack { display: grid; gap: 18px; }
        .field label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 700; color: #4f6478; }
        .field input[type="text"], .field textarea { width: 100%; border: 1px solid var(--line); background: #fff; border-radius: 10px; padding: 10px 12px; font: inherit; font-size: 13px; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .field textarea { resize: vertical; }
        .field input[type="text"]:hover, .field textarea:hover { border-color: #cfd8e2; }
        .field input[type="text"]:focus, .field textarea:focus { border-color: var(--accent); outline: none; box-shadow: 0 0 0 3px var(--accent-soft); }
        .audience-row { display: flex; gap: 16px; flex-wrap: wrap; margin-bottom: 18px; }
        .audience-option { display: inline-flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; text-transform: capitalize; cursor: pointer; }
        .audience-option input[type="checkbox"] { width: 15px; height: 15px; accent-color: var(--accent); cursor: pointer; }
        .submit-row { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .submit-row .action.primary { cursor: pointer; }
        .submit-hint { font-size: 12px; color: var(--muted); }
        .announce-title { font-weight: 700; color: var(--ink); }
        .announce-excerpt { font-size: 12px; color: var(--muted); max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 2px; }
        .audiences-cell { text-transform: capitalize; }
        .error-detail { font-size: 11px; color: var(--danger); margin-top: 4px; max-width: 260px; }
        .created-cell { color: var(--muted); }
    </style>
@endsection

@section('toolbar_title', 'Platform Announcements')
@section('toolbar_text', 'Broadcast a message to all students, staff and agents across every institute on the platform.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('content')
    <div class="stack">
        @if(session('status'))
            <div class="flash-ok">{{ session('status') }}</div>
        @endif
        @if(session('error'))
            <div class="flash-error">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="flash-error">
                <ul>
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">New Announcement</div>
            </div>
            <div class="panel-body">
                <form method="POST" action="{{ route('admin.lms.announcements.store') }}">
                    @csrf

                    <div class="field" style="margin-bottom: 16px;">
                        <label for="announcement-title">Title</label>
                        <input type="text" id="announcement-title" name="title" value="{{ old('title') }}" maxlength="255" required
                               placeholder="e.g. Scheduled maintenance this weekend">
                    </div>

                    <div class="field" style="margin-bottom: 16px;">
                        <label for="announcement-body">Message</label>
                        <textarea id="announcement-body" name="body" rows="5" maxlength="5000" required
                                  placeholder="Write the announcement everyone will receive as a notification and an email.">{{ old('body') }}</textarea>
                    </div>

                    <label style="display:block;margin-bottom:8px;font-size:12px;font-weight:700;color:#4f6478">Send to</label>
                    @php $oldAudiences = old('audiences', $audienceOptions); @endphp
                    <div class="audience-row">
                        @foreach($audienceOptions as $audience)
                            <label class="audience-option">
                                <input type="checkbox" name="audiences[]" value="{{ $audience }}"
                                       {{ in_array($audience, $oldAudiences) ? 'checked' : '' }}>
                                {{ $audience }}s
                            </label>
                        @endforeach
                    </div>

                    <div class="submit-row">
                        <button type="submit" class="action primary">Send Announcement</button>
                        <span class="submit-hint">Delivered as an in-app notification and an email to every recipient, on every institute.</span>
                    </div>
                </form>
            </div>
        </div>

        <div class="panel">
            <div class="panel-head">
                <div class="panel-title">Recent Announcements</div>
            </div>
            @if(count($announcements ?? []) === 0)
                <div class="panel-empty">No announcements sent yet.</div>
            @else
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Audiences</th>
                                <th>Status</th>
                                <th>Recipients</th>
                                <th>Created</th>
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
                                    <td>
                                        <div class="announce-title">{{ $a->title }}</div>
                                        <div class="announce-excerpt">{{ $a->body }}</div>
                                    </td>
                                    <td class="audiences-cell">{{ implode(', ', (array) $a->audiences) }}</td>
                                    <td>
                                        <span class="course-state {{ $stateClass }}">{{ ucfirst($a->status) }}</span>
                                        @if($a->status === 'failed' && $a->error)
                                            <div class="error-detail">{{ $a->error }}</div>
                                        @endif
                                    </td>
                                    <td class="num">{{ $a->recipients_count }}</td>
                                    <td class="num created-cell">{{ $a->created_at }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
@endsection
