@extends('emails.layout', [
    'brandName' => $brandName ?? null,
    'brandColor' => $brandColor ?? null,
    'preheader' => 'Reset your ' . $portalLabel . ' password — link expires in 30 minutes.',
])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hi {{ $name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Reset your password</h1>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
    We received a request to reset your {{ $portalLabel }} password. Tap the button below to choose a new one.
  </p>
  @include('emails.partials.button', ['url' => $resetLink, 'label' => 'Reset password', 'color' => $brandColor ?? null])
  @include('emails.partials.fallback-link', ['url' => $resetLink, 'color' => $brandColor ?? null])
  <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#888;">
    This link expires in 30 minutes. If you didn't request this, you can safely ignore this email — your password won't change.
  </p>
@endsection
