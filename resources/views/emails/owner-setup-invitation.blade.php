@extends('emails.layout', ['brandName' => $brand, 'preheader' => 'Set up your owner account — this link expires in 7 days.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $ownerName }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Set up your owner account</h1>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
    You're listed as the owner for <strong>{{ $entityName }}</strong>. Set up your account below to access your owner console.
  </p>
  @include('emails.partials.button', ['url' => $setupUrl, 'label' => 'Set up your account'])
  @include('emails.partials.fallback-link', ['url' => $setupUrl])
  <p style="margin:24px 0 0;font-size:13px;line-height:1.6;color:#888;">
    This link expires in 7 days and can be used once. If you didn't expect this, you can safely ignore this email.
  </p>
@endsection
