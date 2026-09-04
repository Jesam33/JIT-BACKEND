{{-- "Or copy this link" fallback shown under a CTA button. Usage:
     @include('emails.partials.fallback-link', ['url' => $link]) --}}
<p style="margin:20px 0 0;font-size:13px;line-height:1.6;color:#888;">
  Or copy this link into your browser:<br>
  <a href="{{ $url }}" style="color:#ed180d;word-break:break-all;">{{ $url }}</a>
</p>
