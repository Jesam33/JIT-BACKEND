{{-- The "or copy this link" fallback shown under a CTA, centred like the
     reference. Usage:
     @include('emails.partials.fallback-link', ['url' => $link])
     Optional: ['color' => '#ed180d'] to tint the URL with the brand accent. --}}
@php $linkColor = trim((string) ($color ?? '')) !== '' ? $color : '#ed180d'; @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">
  <tr>
    <td align="center" style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#6b7280;">
      Or copy and paste this link into your browser:<br>
      <a href="{{ $url }}" style="color:{{ $linkColor }};text-decoration:underline;word-break:break-all;">{{ $url }}</a>
    </td>
  </tr>
</table>
