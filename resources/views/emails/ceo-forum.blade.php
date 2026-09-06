@php
    $isReminder = ($mode ?? 'invite') === 'reminder';
    $preheader = $isReminder
        ? 'Your CEO\'s Forum is starting soon. Join from your owner console.'
        : 'You\'re invited to a live CEO\'s Forum with Jorsas Tech.';
@endphp
@extends('emails.layout', ['brandName' => 'Jorsas Tech', 'preheader' => $preheader])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $ownerName }},</p>

  @if($isReminder)
    <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your CEO's Forum starts soon</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#444;">
      A quick reminder that the session below is about to begin. Join live from your owner console.
    </p>
  @else
    <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">You're invited to a CEO's Forum</h1>
    <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#444;">
      Jorsas Tech is hosting a live session for academy owners. Here are the details.
    </p>
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 6px;font-size:17px;font-weight:700;color:#1a1a1a;">{{ $forumTitle }}</p>
        @if($forumTopic)
          <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#555;">{{ $forumTopic }}</p>
        @endif
        <p style="margin:0 0 4px;font-size:14px;color:#333;"><strong>When:</strong> {{ $whenLine }}</p>
        <p style="margin:0 0 4px;font-size:14px;color:#333;"><strong>Duration:</strong> {{ $durationMinutes }} minutes</p>
        <p style="margin:0;font-size:14px;color:#333;"><strong>Host:</strong> {{ $forumHost }}</p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $joinUrl, 'label' => $isReminder ? 'Join the forum' : 'View the forum'])
  @include('emails.partials.fallback-link', ['url' => $joinUrl])

  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    You join live from your owner console. The join button lights up 10 minutes before the start time. An invitation to your calendar is attached to this email.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
