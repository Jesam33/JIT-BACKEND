{{--
  The shared shell for every transactional email.

  Structure, top to bottom, matching the reference design: a white card sitting
  flat on grey with NO border and NO shadow, a centred logo, the email's own
  heading, then the body, then a solid hairline divider and a fixed footer block
  (help, social, copyright, legal).

  Vars:
    $brandName    - display name; also the wordmark fallback and the <title>
    $brandColor   - hex accent; drives the CTA button
    $preheader    - hidden preview line
    $platformMail - true when the PLATFORM is the sender, which shows the
                    "Need Help?" block. False (the default) on academy mail.

  Sections: content (required), footer (optional note under the footer block).

  Email-client rules kept throughout: inline styles only, tables not flexbox, a
  fixed 600px card that degrades with max-width, and raster icons rather than
  SVG or an icon font.
--}}
@php
    $brand = trim($brandName ?? '') !== '' ? $brandName : (config('mail.from.name') ?: 'Jorsas');
    $accent = trim($brandColor ?? '') !== '' ? $brandColor : '#ed180d';

    // The header mark is ALWAYS the platform's own logo, never an academy's
    // upload, and the wordmark fallback is the platform NAME for the same reason:
    // an academy's name does not belong in the header at all. The wordmark only
    // appears when the logo URL is unusable (a dev host), never as a per-academy
    // identity. $brand stays the per-sender name used for the document title and
    // the sender line, which is invisible in the message body.
    $platformName = (string) config('mail.from.name') ?: 'Jorsas';
    $logo = trim((string) config('saas.platform_mail_logo'));
    // A logo served from a dev host is worse than no logo: the recipient gets a
    // broken-image icon in the header, so fall back to the wordmark instead.
    if ($logo !== '' && preg_match('#^https?://(localhost|127\.0\.0\.1|\[::1\])(:\d+)?(/|$)#i', $logo)) {
        $logo = '';
    }

    // Whether the PLATFORM is the sender of this message. It gates
    // platform-only chrome, currently the "Need Help?" block: on academy mail
    // the academy is the sender and its support details are not ours to publish.
    // Defaults to false so an academy email can never leak our contact details
    // by omission; the cost of forgetting is only a missing block.
    $platformMail = (bool) ($platformMail ?? false);

    // Footer icons are hosted PNGs, resolved by App\Support\EmailIcons, which
    // returns '' when the configured base is unreachable from an inbox. Each
    // partial resolves its own icons through that helper rather than reading a
    // value from here, because a partial included from a TEMPLATE does not see
    // the layout's variables (section content is captured in the child scope).
    $frontendUrl = rtrim((string) config('saas.frontend_url'), '/');
    $support = (array) config('saas.email_footer', []);
    // Academy mail routes support to the academy's own contact address when it
    // has one, so a student writes to the people who can actually help.
    $supportEmail = trim((string) ($supportEmail ?? '')) !== ''
        ? trim((string) $supportEmail)
        : trim((string) ($support['support_email'] ?? ''));
    $supportPhone = trim((string) ($support['support_phone'] ?? ''));
    $supportHours = trim((string) ($support['support_hours'] ?? ''));
    $legalLinks = array_values(array_filter((array) ($support['legal_links'] ?? []), fn ($l) => ! empty($l['url']) && ! empty($l['label'])));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="x-apple-disable-message-reformatting">
<meta name="color-scheme" content="light">
<title>{{ $brand }}</title>
</head>
<body style="margin:0;padding:0;background:#ebebeb;-webkit-text-size-adjust:100%;">

{{-- Hidden preview line. Kept out of the layout flow so it never shows in the
     body of the message. --}}
@if (trim((string) ($preheader ?? '')) !== '')
<div style="display:none;font-size:1px;color:#ebebeb;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;">{{ $preheader }}</div>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background:#ebebeb;">
  <tr>
    <td align="center" style="padding:40px 16px;">

      <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" style="width:600px;max-width:100%;background:#ffffff;border-radius:16px;">
        <tr>
          <td style="padding:40px 40px 36px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">

            {{-- Header mark: the logo alone, no academy name beside it. --}}
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
              <tr>
                <td align="center" style="padding:0 0 4px;">
                  @if ($logo !== '')
                    <img src="{{ $logo }}" alt="{{ $platformName }}" width="60" height="64" style="display:block;margin:0 auto;width:60px;height:64px;border:0;outline:none;text-decoration:none;">
                  @else
                    {{-- Platform wordmark, in the platform red rather than the
                         sender's accent: the header is platform identity now,
                         while $accent still drives the CTA button and links. --}}
                    <span style="display:inline-block;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:700;letter-spacing:0.2px;color:#ed180d;">{{ $platformName }}</span>
                  @endif
                </td>
              </tr>
            </table>

            @yield('content')

            @include('emails.partials.divider')

            {{-- Fixed footer chrome. --}}
            @hasSection('footer')
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 26px;">
                <tr>
                  <td align="center" style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:1.6;color:#9ca3af;">
                    @yield('footer')
                  </td>
                </tr>
              </table>
            @endif

            @if ($platformMail)
              @include('emails.partials.help', [
                'supportEmail' => $supportEmail,
                'supportPhone' => $supportPhone,
                'supportHours' => $supportHours,
              ])
            @endif

            @include('emails.partials.social')

            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 0;">
              <tr>
                <td align="center" style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.7;color:#9ca3af;">
                  &copy; {{ date('Y') }} Jorsas Tech. All rights reserved.
                  @if ($legalLinks)
                    <br>
                    @foreach ($legalLinks as $i => $link)
                      @if ($i > 0) <span style="color:#d1d5db;">&nbsp;|&nbsp;</span> @endif
                      <a href="{{ $link['url'] }}" style="color:#6b7280;text-decoration:underline;">{{ $link['label'] }}</a>
                    @endforeach
                  @endif
                </td>
              </tr>
            </table>

          </td>
        </tr>
      </table>

    </td>
  </tr>
</table>
</body>
</html>
