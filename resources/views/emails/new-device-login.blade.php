@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => "New sign-in from {$device}",
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'New sign-in to your account',
    'subtitle' => 'If this was you, nothing to do',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $greeting }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    Your account at {{ $academy }} was just signed into from a device we have not seen before.
  </p>

  {{-- The three facts that let someone recognise their own machine, or not.
       Rendered as a plain left-aligned list rather than a table so it stays
       readable in a narrow mobile client. --}}
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:16px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#374151;">
        <strong style="color:#111827;">Device:</strong> {{ $device }}<br>
        <strong style="color:#111827;">IP address:</strong> {{ $ip }}<br>
        <strong style="color:#111827;">When:</strong> {{ $when }}
      </td>
    </tr>
  </table>

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'shield',
    'title' => 'If this was not you',
    'body' => 'Change your password straight away, then sign out all devices from your profile. If you cannot get back in, contact your academy.',
  ])

  @include('emails.partials.button', ['url' => $ctaUrl, 'label' => 'Review your devices', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $ctaUrl, 'color' => $brand['color']])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because your account was signed into from a new device.</p>
@endsection
