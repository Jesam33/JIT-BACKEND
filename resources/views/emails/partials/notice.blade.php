{{-- A soft grey advisory box: no border, no shadow, just a tinted panel with an
     icon, a bold title and supporting text. Usage:
     @include('emails.partials.notice', [
       'title' => 'Security Notice',
       'body'  => 'This link expires in 24 hours.',
       'icon'  => 'shield',        // shield | mail | phone | clock | info
     ])
     The icon is a hosted PNG; if icons are unavailable the box renders as text
     only rather than showing a broken image. --}}
@php
    $noticeTitle = trim((string) ($title ?? ''));
    $noticeBody = trim((string) ($body ?? ''));
    // Views cannot resolve the icon base from the layout's scope, so it comes
    // from the helper every time.
    $noticeIcon = \App\Support\EmailIcons::url('icon-' . trim((string) ($icon ?? 'info')) . '.png');
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
  <tr>
    <td style="padding:16px 20px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
        <tr>
          @if ($noticeIcon)
            <td width="26" valign="top" style="width:26px;padding:0 10px 0 0;">
              <img src="{{ $noticeIcon }}" alt="" width="18" height="18" style="display:block;width:18px;height:18px;border:0;outline:none;">
            </td>
          @endif
          <td valign="top" style="font-family:Arial,Helvetica,sans-serif;">
            @if ($noticeTitle !== '')
              <p style="margin:0;font-size:14px;font-weight:700;line-height:1.5;color:#111827;">{{ $noticeTitle }}</p>
            @endif
            @if ($noticeBody !== '')
              <p style="margin:{{ $noticeTitle !== '' ? '6px' : '0' }} 0 0;font-size:14px;line-height:1.6;color:#6b7280;">{{ $noticeBody }}</p>
            @endif
          </td>
        </tr>
      </table>
    </td>
  </tr>
</table>
