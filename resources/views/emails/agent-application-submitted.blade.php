@extends('emails.layout', ['preheader' => 'A new agent application is waiting for review.'])

@section('content')
  <h1 style="margin:0 0 16px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">New agent application</h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Name</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#888;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#888;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong><br>
        <span style="color:#888;">Address</span> &nbsp;<strong>{{ $agent->home_address }}</strong><br>
        <span style="color:#888;">Qualification</span> &nbsp;<strong>{{ $agent->qualification }}</strong>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'Review in admin'])
@endsection
