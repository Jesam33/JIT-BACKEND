@extends('emails.layout', [
    'brandName' => $brand,
    'brandColor' => '#ed180d',
    'preheader' => 'Set up your owner account. This link expires in 7 days.',
    // Jorsas invites the owner, so the help block shows.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Set up your owner account',
    'subtitle' => 'You are listed as the owner of ' . $entityName,
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $ownerName }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    You're listed as the owner for <strong style="color:#111827;">{{ $entityName }}</strong>. Set up
    your account below to access your owner console.
  </p>

  @include('emails.partials.button', ['url' => $setupUrl, 'label' => 'Set up your account'])
  @include('emails.partials.fallback-link', ['url' => $setupUrl])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'clock',
    'title' => 'This link expires in 7 days',
    'body' => 'The link can be used once. If you did not expect this email, you can safely ignore it and nothing will change.',
  ])
@endsection
