@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'brandLogo' => $brand['logo'] ?? null,
    'preheader' => 'A new Admission Marketer application is waiting for your review.',
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'New Admission Marketer application',
    'subtitle' => 'Someone applied to promote your academy',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    An application to join the Admission Marketer Network at {{ $brand['name'] }} is waiting for
    your review. Approve it and they get their own referral code and portal, so they can start
    bringing students in.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Name</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#6b7280;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#6b7280;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong><br>
        <span style="color:#6b7280;">Address</span> &nbsp;<strong>{{ $agent->home_address }}</strong><br>
        <span style="color:#6b7280;">Qualification</span> &nbsp;<strong>{{ $agent->qualification }}</strong>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $reviewUrl, 'label' => 'Review the application', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $reviewUrl, 'color' => $brand['color']])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'info',
    'title' => 'Nothing happens until you decide',
    'body' => 'Applicants cannot refer anyone or earn anything until you approve them, so nobody is added to your academy by applying. You can approve or decline this application from the Agents page in your portal.',
  ])
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
