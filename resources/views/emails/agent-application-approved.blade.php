<p>Congratulations {{ $agent->name }},</p>
<p>Your application to become a Jorsas agent has been approved!</p>
<p><strong>Your Referral Code:</strong> <code style="background:#f0f0f0;padding:4px 8px;border-radius:4px;font-size:16px;">{{ $agent->referral_code }}</code></p>
<p><strong>Login to your dashboard:</strong></p>
<p><a href="{{ $portalUrl }}" style="display:inline-block;background:#ed180d;color:#fff;padding:12px 24px;border-radius:999px;font-size:16px;font-weight:700;text-decoration:none;">Set Up Your Password</a></p>
<p style="margin-top:16px;">Or copy this link: <a href="{{ $portalUrl }}">{{ $portalUrl }}</a></p>
<p>Your email is already filled in. Just enter the temporary password below to sign in:</p>
<p style="font-size:18px;font-weight:700;letter-spacing:2px;background:#f0f0f0;padding:8px 16px;border-radius:6px;display:inline-block;">{{ $password }}</p>
<p style="font-size:13px;color:#666;">You can change your password after logging in from your profile settings.</p>
<p>Start referring students and earning commissions today!</p>
