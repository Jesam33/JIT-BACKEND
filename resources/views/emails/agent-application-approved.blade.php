<p>Congratulations {{ $agent->name }},</p>
<p>Your application to become a Jorsas agent has been approved.</p>
<p><strong>Your Login Credentials:</strong></p>
<ul>
    <li><strong>Portal URL:</strong> <a href="{{ $portalUrl }}">{{ $portalUrl }}</a></li>
    <li><strong>Email:</strong> {{ $agent->email }}</li>
    <li><strong>Password:</strong> (the password you chose during registration)</li>
</ul>
<p><strong>Your Referral Code:</strong> <code style="background:#f0f0f0;padding:4px 8px;border-radius:4px;font-size:16px;">{{ $agent->referral_code }}</code></p>
<p>Start referring students and earning commissions today!</p>
