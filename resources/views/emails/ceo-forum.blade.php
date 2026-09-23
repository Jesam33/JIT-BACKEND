@php
    $accent = '#ed180d';
    $isReminder = ($mode ?? 'invite') === 'reminder';
    $preheader = $isReminder
        ? 'Your CEO\'s Forum is starting soon. Join from your owner console.'
        : 'You\'re invited to a live CEO\'s Forum with Jorsas Tech.';
@endphp
@extends('emails.layout', [
    'brandName' => 'Jorsas Tech',
    'brandColor' => $accent,
    'preheader' => $preheader,
    // Jorsas hosts the forum, so Jorsas is the sender.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => $isReminder ? "Your CEO's Forum starts soon" : "You're invited to a CEO's Forum",
    'subtitle' => $isReminder
        ? 'The session below is about to begin'
        : 'A live session for academy owners, hosted by Jorsas Tech',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $ownerName }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    @if($isReminder)
      A quick reminder that the session below is about to begin. You join live from your owner console.
    @else
      Jorsas Tech is hosting a live session for academy owners. Here are the details.
    @endif
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0;font-size:17px;font-weight:700;line-height:1.4;color:#111827;">{{ $forumTitle }}</p>
        @if($forumTopic)
          <p style="margin:6px 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">{{ $forumTopic }}</p>
        @endif
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">When:</strong> {{ $whenLine }}
        </p>
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">Duration:</strong> {{ $durationMinutes }} minutes
        </p>
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">Host:</strong> {{ $forumHost }}
        </p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $joinUrl, 'label' => $isReminder ? 'Join the forum' : 'View the forum', 'color' => $accent])
  @include('emails.partials.fallback-link', ['url' => $joinUrl, 'color' => $accent])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'clock',
    'title' => 'Joining the session',
    'body' => 'You join live from your owner console. The join button lights up 10 minutes before the start time. An invitation to your calendar is attached to this email.',
  ])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
