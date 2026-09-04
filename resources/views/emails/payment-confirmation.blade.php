@extends('emails.layout', ['brandName' => 'Jorsas Institute of Technology', 'preheader' => 'Payment confirmed — set up your account to start learning.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hi {{ $registration->first_name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">You're in — welcome aboard</h1>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
    Your payment has been confirmed and you've been accepted into
    <strong>{{ $registration->course_name }}</strong>. Set up your account below to access your student portal.
  </p>
  @include('emails.partials.button', ['url' => $setupLink, 'label' => 'Set up my account'])
  @include('emails.partials.fallback-link', ['url' => $setupLink])
@endsection

@section('footer')
  <p style="margin:0;">Jorsas Institute of Technology</p>
@endsection
