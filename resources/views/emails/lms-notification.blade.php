{{-- Notification / announcement email. Inline styles only (email-safe); brand
     red #ed180d + pill CTA match the rest of the app's transactional mail.
     {{ }} escaping is intentional — titles/bodies are user-entered. --}}
<div style="margin:0;padding:0;background:#f4f4f5;">
  <div style="max-width:560px;margin:0 auto;padding:24px 16px;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;">
    <div style="background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ececec;">
      <div style="background:#ed180d;padding:18px 28px;">
        <p style="margin:0;color:#ffffff;font-size:14px;font-weight:700;letter-spacing:0.5px;">{{ $instituteName }}</p>
      </div>
      <div style="padding:28px;">
        <p style="margin:0 0 16px;font-size:15px;color:#555;">Hi {{ $greetingName }},</p>
        <h1 style="margin:0 0 12px;font-size:20px;line-height:1.3;color:#1a1a1a;">{{ $notifTitle }}</h1>
        @if(trim($notifBody) !== '')
          <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">{{ $notifBody }}</p>
        @endif
        <p style="margin:0;">
          <a href="{{ $actionUrl }}" style="display:inline-block;background:#ed180d;color:#ffffff;padding:12px 26px;border-radius:999px;font-size:15px;font-weight:700;text-decoration:none;">{{ $actionLabel }}</a>
        </p>
        <p style="margin:22px 0 0;font-size:13px;color:#888;">
          Or copy this link into your browser:<br>
          <a href="{{ $actionUrl }}" style="color:#ed180d;word-break:break-all;">{{ $actionUrl }}</a>
        </p>
      </div>
    </div>
    <p style="margin:16px 0 0;text-align:center;font-size:12px;color:#aaa;">
      You're receiving this because you have an account on {{ $instituteName }}.
    </p>
  </div>
</div>
