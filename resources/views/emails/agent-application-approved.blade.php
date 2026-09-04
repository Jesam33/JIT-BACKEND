@extends('emails.layout', ['preheader' => 'Your agent application is approved — here is your referral code.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Congratulations {{ $agent->name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your application is approved</h1>
  <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#444;">
    You're now an approved agent. Share your referral code with students and start earning commission on every enrolment.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#1a1a1a;">
        <span style="color:#888;">Your referral code</span><br>
        <strong style="font-size:20px;letter-spacing:2px;">{{ $agent->referral_code }}</strong>
        <div style="height:14px;line-height:14px;">&nbsp;</div>
        <span style="color:#888;">Temporary password</span><br>
        <strong style="font-size:16px;letter-spacing:1px;">{{ $password }}</strong>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $portalUrl, 'label' => 'Set up your password'])
  @include('emails.partials.fallback-link', ['url' => $portalUrl])
  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    Your email is already filled in — just enter the temporary password to sign in, then change it from your profile settings.
  </p>
@endsection
