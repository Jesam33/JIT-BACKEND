@extends('emails.layout', [
    'brandName' => 'Jorsas Institute of Technology',
    'brandColor' => '#ed180d',
    'preheader' => 'A withdrawal request needs processing.',
    // An internal alert to the platform's own admin address.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Withdrawal request',
    'subtitle' => 'An agent has requested a payout',
  ])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Agent</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#6b7280;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#6b7280;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong><br>
        <span style="color:#6b7280;">Amount</span> &nbsp;<strong style="font-size:16px;">&#8358;{{ number_format($amount, 2) }}</strong>
      </td>
    </tr>
  </table>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 10px;font-size:14px;font-weight:700;line-height:1.5;color:#111827;">Bank details</p>
        @if($agent->bank_name || $agent->account_number)
          <p style="margin:0;font-size:14px;line-height:1.9;color:#6b7280;">
            <span style="color:#6b7280;">Bank</span> &nbsp;<strong style="color:#111827;">{{ $agent->bank_name ?? 'Not set' }}</strong><br>
            <span style="color:#6b7280;">Account number</span> &nbsp;<strong style="color:#111827;">{{ $agent->account_number ?? 'Not set' }}</strong><br>
            <span style="color:#6b7280;">Account name</span> &nbsp;<strong style="color:#111827;">{{ $agent->account_name ?? 'Not set' }}</strong>
          </p>
        @else
          <p style="margin:0;font-size:14px;line-height:1.6;color:#6b7280;">No bank details provided yet.</p>
        @endif
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'View pending withdrawals', 'color' => '#059669'])
  @include('emails.partials.fallback-link', ['url' => $adminUrl])

  <p style="margin:20px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#6b7280;">
    Sign in to the admin panel to mark these commissions as paid after processing the payout.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">Jorsas Institute of Technology</p>
@endsection
