<p>A withdrawal request has been submitted.</p>

<p><strong>Agent:</strong> {{ $agent->name }}<br>
<strong>Email:</strong> {{ $agent->email }}<br>
<strong>Phone:</strong> {{ $agent->phone }}<br>
<strong>Amount:</strong> ₦{{ number_format($amount, 2) }}</p>

<p><strong>Bank Details:</strong></p>
@if($agent->bank_name || $agent->account_number)
<ul>
    <li><strong>Bank:</strong> {{ $agent->bank_name ?? 'Not set' }}</li>
    <li><strong>Account Number:</strong> {{ $agent->account_number ?? 'Not set' }}</li>
    <li><strong>Account Name:</strong> {{ $agent->account_name ?? 'Not set' }}</li>
</ul>
@else
<p style="color:#999;">No bank details provided yet.</p>
@endif

<p><a href="{{ $adminUrl }}" style="display:inline-block;background:#059669;color:#fff;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:700;text-decoration:none;">View Pending Withdrawals</a></p>

<p style="font-size:13px;color:#666;">Login to the admin panel to mark these commissions as paid after processing the payout.</p>
