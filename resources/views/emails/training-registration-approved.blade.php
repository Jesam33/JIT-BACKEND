@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => 'Your registration is approved. Continue to the LMS.',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your registration is approved',
    'subtitle' => 'Set up your account and start learning',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $registration->first_name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    Great news: your registration has been approved. Continue to the LMS to finish setting up your
    account and start learning.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Email</span> &nbsp;<strong>{{ $registration->email }}</strong><br>
        <span style="color:#6b7280;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#6b7280;">Price</span> &nbsp;<strong>${{ number_format((float) $registration->course_price, 2) }}</strong><br>
        <span style="color:#6b7280;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $lmsLink, 'label' => 'Go to the LMS', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $lmsLink, 'color' => $brand['color']])
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
