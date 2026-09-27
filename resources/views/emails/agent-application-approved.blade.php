@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'brandLogo' => $brand['logo'] ?? null,
    'preheader' => 'Your agent application is approved. Here is your referral code.',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your application is approved',
    'subtitle' => 'Share your referral code and start earning',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Congratulations {{ $agent->name }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    You're now an approved agent. Share your referral code or your referral link with students and
    start earning commission on every enrolment.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#111827;">
        <span style="color:#6b7280;">Your referral code</span><br>
        <strong style="font-size:20px;letter-spacing:2px;color:#111827;">{{ $agent->referral_code }}</strong>
        @if ($agent->referral_link)
          <div style="height:16px;line-height:16px;">&nbsp;</div>
          {{-- The same code as a link: a student who opens it lands on the academy
               with the code already applied, so nothing has to be typed at
               registration. Only absent for an agent whose academy cannot be
               resolved, in which case the code above still stands alone. --}}
          <span style="color:#6b7280;">Your referral link</span><br>
          <a href="{{ $agent->referral_link }}" style="color:{{ $brand['color'] }};text-decoration:underline;word-break:break-all;font-size:14px;">{{ $agent->referral_link }}</a>
        @endif
        <div style="height:14px;line-height:14px;">&nbsp;</div>
        <span style="color:#6b7280;">Temporary password</span><br>
        <strong style="font-size:16px;letter-spacing:1px;color:#111827;">{{ $password }}</strong>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $portalUrl, 'label' => 'Set up your password', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $portalUrl, 'color' => $brand['color']])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'shield',
    'title' => 'Signing in',
    'body' => 'Your email is already filled in. Just enter the temporary password to sign in, then change it from your profile settings.',
  ])
@endsection
