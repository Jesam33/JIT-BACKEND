{{-- Bulletproof, table-based CTA button. Usage:
     @include('emails.partials.button', ['url' => $link, 'label' => 'Open'])
     Optional: ['color' => '#ed180d'] to override the brand red. --}}
@php $btnColor = $color ?? '#ed180d'; @endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:8px 0;">
  <tr>
    <td align="center" bgcolor="{{ $btnColor }}" style="border-radius:999px;">
      <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:13px 30px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:1;color:#ffffff;text-decoration:none;border-radius:999px;">{{ $label }}</a>
    </td>
  </tr>
</table>
