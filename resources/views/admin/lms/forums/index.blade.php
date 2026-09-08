@php ($activeLmsPage = 'forums') @endphp
@extends('admin.lms.layout')

@section('title', "CEO's Forum")
@section('toolbar_title', "CEO's Forum")
@section('toolbar_text', 'Schedule a live meeting for every institute owner on the platform. Owners are emailed an invitation and a reminder, and join in-portal from their console.')

@section('toolbar_actions')
    <a href="{{ url()->current() }}" class="action">Refresh</a>
@endsection

@section('head')
<style>
    .field-label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 700; color: #30465b; }
    .field-input, .field-textarea {
        width: 100%;
        padding: 10px 12px;
        border: 1px solid var(--line);
        border-radius: 10px;
        background: #fff;
        color: var(--ink);
        font-size: 14px;
        font-family: inherit;
        transition: border-color 0.15s ease;
    }
    .field-textarea { resize: vertical; }
    .field-input:focus, .field-textarea:focus { outline: none; border-color: #cfd8e2; }
    .field-hint { display: block; margin-top: 6px; font-size: 11px; color: var(--muted); }
    .field-grid { display: flex; gap: 16px; flex-wrap: wrap; }
    .form-actions { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .form-hint { font-size: 12px; color: var(--muted); }

    .forum-list { display: flex; flex-direction: column; gap: 14px; }
    .forum-card { border: 1px solid var(--line); border-radius: var(--radius); padding: 16px; background: #fff; }
    .forum-card-top { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; flex-wrap: wrap; }
    .forum-title { font-size: 15px; font-weight: 800; letter-spacing: -0.02em; color: var(--ink); }
    .forum-topic { margin-top: 4px; max-width: 560px; font-size: 13px; line-height: 1.55; color: var(--muted); }
    .forum-meta {
        display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
        margin-top: 10px; font-size: 12px; font-weight: 600; color: #4f6478;
    }
    .forum-meta span { display: inline-flex; align-items: center; gap: 5px; white-space: nowrap; }
    .forum-meta svg { width: 13px; height: 13px; opacity: 0.65; flex: none; }
    .forum-flags { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
    .flag {
        display: inline-flex; align-items: center; padding: 3px 8px;
        border-radius: 7px; font-size: 11px; font-weight: 700;
        background: #eef2f6; color: #7a8c9c;
    }
    .flag.ok { background: var(--success-soft); color: var(--success); }
    .rec-link {
        display: inline-flex; align-items: center; gap: 5px; margin-top: 10px;
        font-size: 12px; font-weight: 700; color: var(--accent); text-decoration: none;
    }
    .rec-link:hover { text-decoration: underline; }
    .rec-link svg { width: 13px; height: 13px; flex: none; }
    .forum-edit { margin-top: 14px; }
    .forum-edit summary { cursor: pointer; font-size: 13px; font-weight: 700; color: #30465b; }
    .forum-edit summary:hover { color: var(--accent); }
    .forum-edit form { margin-top: 12px; }
</style>
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
            <div class="panel-title">Schedule a Forum</div>
            <div></div>
        </div>
        <div class="panel-body">
            <form method="POST" action="{{ route('admin.lms.forums.store') }}">
                @csrf

                <label class="field-label">Title</label>
                <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required
                       placeholder="e.g. Growing your academy: enrolment funnels that convert"
                       class="field-input" style="margin-bottom:16px">

                <label class="field-label">Topic / agenda</label>
                <textarea name="topic" rows="4" maxlength="5000"
                          placeholder="What this session covers. Owners see this in the email and on their forum page."
                          class="field-textarea" style="margin-bottom:16px">{{ old('topic') }}</textarea>

                <div class="field-grid" style="margin-bottom:16px">
                    <div style="flex:1;min-width:220px">
                        <label class="field-label">Date &amp; time</label>
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" required class="field-input">
                        <span class="field-hint">Server time. Owners see it converted to their own local time.</span>
                    </div>
                    <div style="width:180px">
                        <label class="field-label">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" value="{{ old('duration_minutes', 60) }}" min="5" max="1440" required class="field-input">
                    </div>
                </div>

                <div class="field-grid" style="margin-bottom:20px">
                    <div style="flex:1;min-width:220px">
                        <label class="field-label">Host name</label>
                        <input type="text" name="host_name" value="{{ old('host_name') }}" maxlength="255"
                               placeholder="e.g. Jorsas Tech" class="field-input">
                    </div>
                    <div style="flex:1;min-width:220px">
                        <label class="field-label">Cover image URL (optional)</label>
                        <input type="url" name="cover_image" value="{{ old('cover_image') }}" maxlength="2048"
                               placeholder="https://..." class="field-input">
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="action primary" style="padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer">Schedule Forum</button>
                    <span class="form-hint">Every institute owner is emailed an invite, then a reminder about an hour before start.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Forums</div>
            <div></div>
        </div>
        <div class="panel-body">
            @if(count($forums ?? []) === 0)
                <div class="panel-empty">No forums scheduled yet.</div>
            @else
                <div class="forum-list">
                    @foreach($forums as $f)
                        @php
                            $stateClass = match($f->status) {
                                'live' => 'active',
                                'ended' => 'warning',
                                'cancelled' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <div class="forum-card">
                            <div class="forum-card-top">
                                <div style="min-width:0">
                                    <div class="forum-title">{{ $f->title }}</div>
                                    @if($f->topic)
                                        <div class="forum-topic">{{ \Illuminate\Support\Str::limit($f->topic, 220) }}</div>
                                    @endif
                                    <div class="forum-meta">
                                        <span>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="17" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                                            {{ $f->scheduled_at?->format('D, M j Y g:i A') }}
                                        </span>
                                        <span>
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 3"/></svg>
                                            {{ $f->duration_minutes }} min
                                        </span>
                                        @if($f->host_name)
                                            <span>
                                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                                                Host: {{ $f->host_name }}
                                            </span>
                                        @endif
                                    </div>
                                    <div class="forum-flags">
                                        <span class="flag {{ $f->invite_sent_at ? 'ok' : '' }}">Invite: {{ $f->invite_sent_at ? 'sent' : 'pending' }}</span>
                                        <span class="flag {{ $f->reminder_sent_at ? 'ok' : '' }}">Reminder: {{ $f->reminder_sent_at ? 'sent' : 'pending' }}</span>
                                    </div>
                                    @if($f->recording_url)
                                        <a href="{{ $f->recording_url }}" target="_blank" rel="noopener" class="rec-link">
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m22 8-6 4 6 4V8Z"/><rect x="2" y="6" width="14" height="12" rx="2"/></svg>
                                            Recording link
                                        </a>
                                    @endif
                                </div>
                                <span class="course-state {{ $stateClass }}">{{ ucfirst($f->status) }}</span>
                            </div>

                            @if($f->status !== 'cancelled')
                                <details class="forum-edit">
                                    <summary>Edit / set recording</summary>
                                    <form method="POST" action="{{ route('admin.lms.forums.update', $f->id) }}">
                                        @csrf
                                        <div class="field-grid">
                                            <div style="flex:1;min-width:220px">
                                                <label class="field-label">Title</label>
                                                <input type="text" name="title" value="{{ $f->title }}" maxlength="255" required class="field-input">
                                            </div>
                                            <div style="width:200px">
                                                <label class="field-label">Date &amp; time</label>
                                                <input type="datetime-local" name="scheduled_at" value="{{ $f->scheduled_at?->format('Y-m-d\TH:i') }}" required class="field-input">
                                            </div>
                                            <div style="width:150px">
                                                <label class="field-label">Duration (min)</label>
                                                <input type="number" name="duration_minutes" value="{{ $f->duration_minutes }}" min="5" max="1440" required class="field-input">
                                            </div>
                                        </div>
                                        <div style="margin-top:12px">
                                            <label class="field-label">Topic / agenda</label>
                                            <textarea name="topic" rows="2" maxlength="5000" class="field-textarea">{{ $f->topic }}</textarea>
                                        </div>
                                        <div class="field-grid" style="margin-top:12px">
                                            <div style="flex:1;min-width:220px">
                                                <label class="field-label">Host name</label>
                                                <input type="text" name="host_name" value="{{ $f->host_name }}" maxlength="255" class="field-input">
                                            </div>
                                            <div style="flex:1;min-width:220px">
                                                <label class="field-label">Recording URL (after the event)</label>
                                                <input type="url" name="recording_url" value="{{ $f->recording_url }}" maxlength="2048"
                                                       placeholder="https://..." class="field-input">
                                            </div>
                                            <div style="flex:1;min-width:220px">
                                                <label class="field-label">Cover image URL</label>
                                                <input type="url" name="cover_image" value="{{ $f->cover_image }}" maxlength="2048"
                                                       placeholder="https://..." class="field-input">
                                            </div>
                                        </div>
                                        <div style="margin-top:12px">
                                            <button type="submit" class="action primary" style="padding:9px 16px;font-size:12px;font-weight:700;cursor:pointer">Save changes</button>
                                        </div>
                                    </form>

                                    <form method="POST" action="{{ route('admin.lms.forums.cancel', $f->id) }}"
                                          onsubmit="return confirm('Cancel this forum? Owners will no longer be able to join it.')"
                                          style="margin-top:10px">
                                        @csrf
                                        <button type="submit" class="action" style="padding:9px 16px;font-size:12px;font-weight:700;color:var(--danger);cursor:pointer">Cancel forum</button>
                                    </form>
                                </details>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
