@extends('emails.layout', ['brandName' => $brand, 'preheader' => 'Your ' . $label . ' is ready — sign in to your owner console.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $ownerName }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your {{ $label }} is ready</h1>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
    <strong>{{ $entityName }}</strong> is all set up. Sign in to your owner console to add courses, invite staff, and welcome your first students.
  </p>
  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'Go to dashboard'])
  @include('emails.partials.fallback-link', ['url' => $adminUrl])
@endsection
