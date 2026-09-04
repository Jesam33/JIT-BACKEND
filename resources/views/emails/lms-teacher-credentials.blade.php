@extends('emails.layout', ['preheader' => 'Your staff account is ready — sign in and set a new password.'])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $teacher->name }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Your staff account is ready</h1>
  <p style="margin:0 0 22px;font-size:15px;line-height:1.6;color:#444;">
    An account has been created for you. Use the details below to sign in, then change your password from your profile straight away.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 8px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#1a1a1a;">
        <span style="color:#888;">Email</span><br>
        <strong style="font-size:15px;">{{ $teacher->email }}</strong>
        <div style="height:12px;line-height:12px;">&nbsp;</div>
        <span style="color:#888;">Temporary password</span><br>
        <strong style="font-size:16px;letter-spacing:1px;">{{ $plainPassword }}</strong>
      </td>
    </tr>
  </table>

  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    For your security, please change this password the first time you sign in.
  </p>
@endsection
