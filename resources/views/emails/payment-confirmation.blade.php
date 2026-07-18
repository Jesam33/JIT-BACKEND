<div style="font-family: sans-serif; max-width: 600px; margin: auto;">
    <h2>Welcome to Jorsas Institute of Technology!</h2>
    <p>Hi <strong>{{ $registration->first_name }}</strong>,</p>
    <p>Your payment has been confirmed and you have been accepted into <strong>{{ $registration->course_name }}</strong>.</p>
    <p>Click the button below to set your password and access your student portal:</p>
    <p style="text-align: center;">
        <a href="{{ $setupLink }}" style="display: inline-block; background: #000; color: #fff; padding: 14px 28px; border-radius: 8px; text-decoration: none; font-weight: bold;">
            Set Up My Account
        </a>
    </p>
    <p>Or copy this link into your browser:</p>
    <p style="word-break: break-all; color: #555;">{{ $setupLink }}</p>
    <hr>
    <p style="color: #888; font-size: 12px;">Jorsas Institute of Technology</p>
</div>
