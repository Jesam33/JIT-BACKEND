@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => 'Payment confirmed. Set up your account to start learning.',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => "You're in. Welcome aboard",
    'subtitle' => 'Your payment is confirmed and your place is secured',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $registration->first_name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    Your payment has been confirmed and you've been accepted into
    <strong style="color:#111827;">{{ $registration->course_name }}</strong>. Set up your account
    below to access your student portal.
  </p>

  @include('emails.partials.button', ['url' => $setupLink, 'label' => 'Set up my account', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $setupLink, 'color' => $brand['color']])
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
