@extends('emails.layout', ['brandName' => 'Jorsas Institute of Technology', 'preheader' => 'Your registration is approved — continue to the LMS.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hi {{ $registration->first_name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your registration is approved</h1>
  <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
    Great news — your registration has been approved. Continue to the LMS to finish setting up your account and start learning.
  </p>
  @include('emails.partials.button', ['url' => $lmsLink, 'label' => 'Go to the LMS'])
  @include('emails.partials.fallback-link', ['url' => $lmsLink])

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Email</span> &nbsp;<strong>{{ $registration->email }}</strong><br>
        <span style="color:#888;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#888;">Price</span> &nbsp;<strong>${{ number_format((float) $registration->course_price, 2) }}</strong><br>
        <span style="color:#888;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
      </td>
    </tr>
  </table>
@endsection

@section('footer')
  <p style="margin:0;">Jorsas Institute of Technology</p>
@endsection
