{{-- Centred CTA button. Table-based with a solid background, because Outlook's
     Word engine ignores padding and border-radius on an <a>. Usage:
     @include('emails.partials.button', ['url' => $link, 'label' => 'Verify Email Address'])
     Optional: ['color' => '#ed180d'] to match the brand accent (null/'' → red). --}}
@php
    $btnColor = trim((string) ($color ?? '')) !== '' ? $color : '#ed180d';
    $btnLabel = trim((string) ($label ?? '')) !== '' ? $label : 'Open';
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto;">
  <tr>
    <td align="center" bgcolor="{{ $btnColor }}" style="border-radius:8px;background:{{ $btnColor }};">
      <a href="{{ $url }}" target="_blank" style="display:inline-block;padding:14px 30px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;line-height:1.2;color:#ffffff;text-decoration:none;border-radius:8px;">{{ $btnLabel }}</a>
    </td>
  </tr>
</table>
