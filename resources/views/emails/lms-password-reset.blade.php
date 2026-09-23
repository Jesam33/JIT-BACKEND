@php
    $accent = trim((string) ($brandColor ?? '')) !== '' ? $brandColor : '#ed180d';
    // Read the real expiry rather than hardcoding it: the copy previously said
    // 30 minutes while config/auth.php expires the token at 60.
    $expireMinutes = (int) config('auth.passwords.users.expire', 60);
@endphp
@extends('emails.layout', [
    'brandName' => $brandName ?? null,
    'brandColor' => $accent,
    'preheader' => 'Reset your ' . $portalLabel . ' password. The link expires in ' . $expireMinutes . ' minutes.',
    // The platform's own "Need Help?" block only; an academy-branded reset is
    // never stamped with the platform's support details.
    'platformMail' => $isPlatformMail ?? false,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Reset Your Password',
    'subtitle' => 'We received a request to reset your ' . $portalLabel . ' password',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    We received a request to reset the password for your {{ $portalLabel }} account. To choose a
    new password, click the button below.
  </p>

  @include('emails.partials.button', ['url' => $resetLink, 'label' => 'Reset Password', 'color' => $accent])
  @include('emails.partials.fallback-link', ['url' => $resetLink, 'color' => $accent])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'shield',
    'title' => 'Security Notice',
    'body' => 'This link will expire in ' . $expireMinutes . ' minutes. For your security, please do not share this email with anyone. If you did not request a password reset, you can safely ignore it and your password will not change.',
  ])
@endsection
