@extends('emails.layout', [
    'brandName' => 'Jorsas Institute of Technology',
    'brandColor' => '#ed180d',
    'preheader' => 'A new agent application is waiting for review.',
    // An internal alert to the platform's own admin address.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'New agent application',
    'subtitle' => 'An application is waiting for review',
  ])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

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

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'Review in admin'])
  @include('emails.partials.fallback-link', ['url' => $adminUrl])
@endsection
