@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => ($requiresPayment ? 'Complete your enrolment in ' : 'Set up your account to join ') . $registration->course_name,
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => $requiresPayment ? "You're invited to enrol" : "You're invited to join",
    'subtitle' => $registration->course_name,
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $registration->first_name ?: 'there' }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    @if ($requiresPayment)
      {{ $brand['name'] }} has invited you to enrol in <strong style="color:#111827;">{{ $registration->course_name }}</strong>.
      Complete your enrolment below to secure your place. You'll set your password right after payment.
    @else
      {{ $brand['name'] }} has invited you to join <strong style="color:#111827;">{{ $registration->course_name }}</strong>.
      Set up your account below to get started. Your place is already reserved.
    @endif
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#6b7280;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
        @if ($requiresPayment)
          <br><span style="color:#6b7280;">Fee</span> &nbsp;<strong>{{ $priceDisplay }}</strong>
        @endif
      </td>
    </tr>
  </table>

  @include('emails.partials.button', [
    'url' => $link,
    'label' => $requiresPayment ? 'Complete enrolment & pay' : 'Set up my account',
    'color' => $brand['color'],
  ])
  @include('emails.partials.fallback-link', ['url' => $link, 'color' => $brand['color']])
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
