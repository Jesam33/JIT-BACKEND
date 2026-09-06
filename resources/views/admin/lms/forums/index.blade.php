@extends('admin.lms.layout')

@section('title', "CEO's Forum")
@section('toolbar_title', "CEO's Forum")
@section('toolbar_text', 'Schedule a live meeting for every institute owner on the platform. Owners are emailed an invitation and a reminder, and join in-portal from their console.')

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
            <div class="panel-title">Schedule a Forum</div>
            <div></div>
        </div>
        <div style="padding:18px">
            <form method="POST" action="{{ route('admin.lms.forums.store') }}">
                @csrf

                <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Title</label>
                <input type="text" name="title" value="{{ old('title') }}" maxlength="255" required
                       placeholder="e.g. Growing your academy: enrolment funnels that convert"
                       style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px;margin-bottom:16px">

                <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Topic / agenda</label>
                <textarea name="topic" rows="4" maxlength="5000"
                          placeholder="What this session covers. Owners see this in the email and on their forum page."
                          style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px;font-family:inherit;resize:vertical;margin-bottom:16px">{{ old('topic') }}</textarea>

                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
                    <div style="flex:1;min-width:220px">
                        <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Date &amp; time</label>
                        <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}" required
                               style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                        <span style="font-size:11px;color:var(--muted)">Server time. Owners see it converted to their own local time.</span>
                    </div>
                    <div style="width:180px">
                        <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Duration (minutes)</label>
                        <input type="number" name="duration_minutes" value="{{ old('duration_minutes', 60) }}" min="5" max="1440" required
                               style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                    </div>
                </div>

                <div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:20px">
                    <div style="flex:1;min-width:220px">
                        <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Host name</label>
                        <input type="text" name="host_name" value="{{ old('host_name') }}" maxlength="255"
                               placeholder="e.g. Jorsas Tech"
                               style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                    </div>
                    <div style="flex:1;min-width:220px">
                        <label style="display:block;margin-bottom:6px;font-size:13px;font-weight:700;color:#30465b">Cover image URL (optional)</label>
                        <input type="url" name="cover_image" value="{{ old('cover_image') }}" maxlength="2048"
                               placeholder="https://..."
                               style="width:100%;padding:10px 12px;border:1px solid var(--line);border-radius:10px;font-size:14px">
                    </div>
                </div>

                <div style="display:flex;align-items:center;gap:12px">
                    <button type="submit" class="action primary" style="padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer">Schedule Forum</button>
                    <span style="font-size:12px;color:var(--muted)">Every institute owner is emailed an invite, then a reminder about an hour before start.</span>
                </div>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div class="panel-title">Forums</div>
            <div></div>
        </div>
        <div style="padding:16px">
            @if(count($forums ?? []) === 0)
                <div class="panel-empty">No forums scheduled yet.</div>
            @else
                <div style="display:flex;flex-direction:column;gap:14px">
                    @foreach($forums as $f)
                        @php
                            $stateClass = match($f->status) {
                                'live' => 'active',
                                'ended' => 'warning',
                                'cancelled' => 'danger',
                                default => 'warning',
                            };
                        @endphp
                        <div style="border:1px solid #e6eef6;border-radius:14px;padding:16px">
                            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start">
                                <div style="min-width:0">
                                    <div style="font-weight:700;color:#30465b;font-size:15px">{{ $f->title }}</div>
                                    @if($f->topic)
                                        <div style="font-size:13px;color:var(--muted);margin-top:4px;max-width:560px">{{ \Illuminate\Support\Str::limit($f->topic, 220) }}</div>
                                    @endif
                                    <div style="font-size:12px;color:#4f6478;margin-top:8px">
                                        {{ $f->scheduled_at?->format('D, M j Y g:i A') }}
                                        &middot; {{ $f->duration_minutes }} min
                                        @if($f->host_name) &middot; Host: {{ $f->host_name }} @endif
                                    </div>
                                    <div style="font-size:12px;margin-top:6px;color:var(--muted)">
                                        Invite: {{ $f->invite_sent_at ? 'sent' : 'pending' }}
                                        &middot; Reminder: {{ $f->reminder_sent_at ? 'sent' : 'pending' }}
                                    </div>
                                    @if($f->recording_url)
                                        <div style="font-size:12px;margin-top:6px"><a href="{{ $f->recording_url }}" target="_blank" rel="noopener">Recording link</a></div>
                                    @endif
                                </div>
                                <span class="course-state {{ $stateClass }}">{{ ucfirst($f->status) }}</span>
                            </div>

                            @if($f->status !== 'cancelled')
                                <details style="margin-top:14px">
                                    <summary style="cursor:pointer;font-size:13px;font-weight:700;color:#30465b">Edit / set recording</summary>
                                    <form method="POST" action="{{ route('admin.lms.forums.update', $f->id) }}" style="margin-top:12px">
                                        @csrf
                                        <div style="display:flex;gap:12px;flex-wrap:wrap">
                                            <div style="flex:1;min-width:220px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Title</label>
                                                <input type="text" name="title" value="{{ $f->title }}" maxlength="255" required
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                            <div style="width:200px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Date &amp; time</label>
                                                <input type="datetime-local" name="scheduled_at" value="{{ $f->scheduled_at?->format('Y-m-d\TH:i') }}" required
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                            <div style="width:140px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Duration (min)</label>
                                                <input type="number" name="duration_minutes" value="{{ $f->duration_minutes }}" min="5" max="1440" required
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                        </div>
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                                            <div style="flex:1;min-width:220px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Topic / agenda</label>
                                                <textarea name="topic" rows="2" maxlength="5000"
                                                          style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;font-family:inherit;resize:vertical">{{ $f->topic }}</textarea>
                                            </div>
                                        </div>
                                        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:12px">
                                            <div style="flex:1;min-width:220px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Host name</label>
                                                <input type="text" name="host_name" value="{{ $f->host_name }}" maxlength="255"
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                            <div style="flex:1;min-width:220px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Recording URL (after the event)</label>
                                                <input type="url" name="recording_url" value="{{ $f->recording_url }}" maxlength="2048"
                                                       placeholder="https://..."
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                            <div style="flex:1;min-width:220px">
                                                <label style="display:block;margin-bottom:4px;font-size:12px;font-weight:700;color:#30465b">Cover image URL</label>
                                                <input type="url" name="cover_image" value="{{ $f->cover_image }}" maxlength="2048"
                                                       placeholder="https://..."
                                                       style="width:100%;padding:8px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px">
                                            </div>
                                        </div>
                                        <div style="margin-top:12px">
                                            <button type="submit" class="action primary" style="padding:8px 14px;font-size:12px;font-weight:700;cursor:pointer">Save changes</button>
                                        </div>
                                    </form>

                                    <form method="POST" action="{{ route('admin.lms.forums.cancel', $f->id) }}"
                                          onsubmit="return confirm('Cancel this forum? Owners will no longer be able to join it.')"
                                          style="margin-top:10px">
                                        @csrf
                                        <button type="submit" class="action" style="padding:8px 14px;font-size:12px;color:var(--danger);cursor:pointer">Cancel forum</button>
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
