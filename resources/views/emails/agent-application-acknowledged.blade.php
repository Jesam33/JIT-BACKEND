@extends('emails.layout', ['brandName' => $brand['name'], 'brandColor' => $brand['color'], 'preheader' => 'We received your Admission Marketer application. Here is what happens next.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $agent->name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your application is in</h1>
  <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#444;">
    Thank you for applying to become an Admission Marketer with {{ $brand['name'] }}. We have received your details and our team will review them shortly.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Name</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#888;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#888;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong>
      </td>
    </tr>
  </table>

  <h2 style="margin:0 0 10px;font-size:16px;line-height:1.3;color:#1a1a1a;font-weight:700;">What happens next</h2>
  <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#444;">
    Once your application is approved, we will email you again with your referral code and a link to set up your Admission Marketer portal. From there you can start referring students and earning commission on every enrolment.
  </p>

  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    You do not need to do anything for now. If any of the details above look wrong, just reply to this email and let us know.
  </p>
@endsection
