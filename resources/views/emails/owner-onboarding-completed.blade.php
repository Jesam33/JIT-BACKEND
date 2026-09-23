@extends('emails.layout', [
    'brandName' => $brand,
    'brandColor' => '#ed180d',
    'preheader' => 'Your ' . $label . ' is ready. Sign in to your owner console.',
    // Jorsas provisions the academy and sends this, so the help block shows.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your ' . $label . ' is ready',
    'subtitle' => 'Sign in to your owner console to get started',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $ownerName }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    <strong style="color:#111827;">{{ $entityName }}</strong> is all set up. Sign in to your owner
    console to add courses, invite staff, and welcome your first students.
  </p>

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'Go to dashboard'])
  @include('emails.partials.fallback-link', ['url' => $adminUrl])
@endsection
