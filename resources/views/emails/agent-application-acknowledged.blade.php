@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => 'We received your Admission Marketer application. Here is what happens next.',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your application is in',
    'subtitle' => 'We have received your details and will review them shortly',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $agent->name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    Thank you for applying to become an Admission Marketer with {{ $brand['name'] }}. Our team will
    review your details shortly.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Name</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#6b7280;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#6b7280;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong>
      </td>
    </tr>
  </table>

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'info',
    'title' => 'What happens next',
    'body' => 'Once your application is approved, we will email you again with your referral code and a link to set up your Admission Marketer portal. From there you can start referring students and earning commission on every enrolment.',
  ])

  <p style="margin:20px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#6b7280;">
    You do not need to do anything for now. If any of the details above look wrong, just reply to
    this email and let us know.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
