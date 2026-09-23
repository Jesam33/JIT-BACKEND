@extends('emails.layout', [
    'brandName' => 'Jorsas Tech',
    'brandColor' => '#ed180d',
    'preheader' => 'Your staff account is ready. Sign in and set a new password.',
    // An academy admin creates the staff account, so this is not platform mail
    // and carries no Jorsas support block.
    'platformMail' => false,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your staff account is ready',
    'subtitle' => 'Sign in with the details below, then set a new password',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $teacher->name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    An account has been created for you. Use the details below to sign in, then change your password
    from your profile straight away.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#111827;">
        <span style="color:#6b7280;">Email</span><br>
        <strong style="font-size:15px;color:#111827;">{{ $teacher->email }}</strong>
        <div style="height:12px;line-height:12px;">&nbsp;</div>
        <span style="color:#6b7280;">Temporary password</span><br>
        <strong style="font-size:16px;letter-spacing:1px;color:#111827;">{{ $plainPassword }}</strong>
      </td>
    </tr>
  </table>

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'shield',
    'title' => 'Change this password',
    'body' => 'For your security, please change this password the first time you sign in.',
  ])
@endsection
