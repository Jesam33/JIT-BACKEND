@extends('emails.layout', ['preheader' => 'A withdrawal request needs processing.'])

@section('content')
  <h1 style="margin:0 0 16px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">Withdrawal request</h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Agent</span> &nbsp;<strong>{{ $agent->name }}</strong><br>
        <span style="color:#888;">Email</span> &nbsp;<strong>{{ $agent->email }}</strong><br>
        <span style="color:#888;">Phone</span> &nbsp;<strong>{{ $agent->phone }}</strong><br>
        <span style="color:#888;">Amount</span> &nbsp;<strong style="font-size:16px;">&#8358;{{ number_format($amount, 2) }}</strong>
      </td>
    </tr>
  </table>

  <p style="margin:0 0 8px;font-size:14px;font-weight:700;color:#1a1a1a;">Bank details</p>
  @if($agent->bank_name || $agent->account_number)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 24px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
      <tr>
        <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
          <span style="color:#888;">Bank</span> &nbsp;<strong>{{ $agent->bank_name ?? 'Not set' }}</strong><br>
          <span style="color:#888;">Account number</span> &nbsp;<strong>{{ $agent->account_number ?? 'Not set' }}</strong><br>
          <span style="color:#888;">Account name</span> &nbsp;<strong>{{ $agent->account_name ?? 'Not set' }}</strong>
        </td>
      </tr>
    </table>
  @else
    <p style="margin:0 0 24px;font-size:14px;color:#999;">No bank details provided yet.</p>
  @endif

  @include('emails.partials.button', ['url' => $adminUrl, 'label' => 'View pending withdrawals', 'color' => '#059669'])
  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    Sign in to the admin panel to mark these commissions as paid after processing the payout.
  </p>
@endsection
