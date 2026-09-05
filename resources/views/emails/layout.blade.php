{{-- Shared, email-safe layout for every transactional email.
     Inline styles only (no <style>/external CSS — many clients strip them).
     Single-column, 560px, table-based wrapper for Outlook.

     Variables (all optional, sensible fallbacks):
       $brandName   — header wordmark (defaults to the platform mail name)
       $brandColor  — header background hex; the per-institute white-label
                      accent so an academy's email matches its storefront
                      (defaults to the platform red #ed180d)
       $preheader   — hidden inbox-preview line
     Sections:
       @section('content')  — the body (required)
       @section('footer')   — overrides the default footer note --}}
@php
    $brand = trim($brandName ?? '') !== '' ? $brandName : (config('mail.from.name') ?: 'Jorsas');
    $headerColor = trim($brandColor ?? '') !== '' ? $brandColor : '#ed180d';
@endphp
<!DOCTYPE html>
<html lang="en" xmlns="http://www.w3.org/1999/xhtml">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="x-apple-disable-message-reformatting">
  <title>{{ $brand }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f5;-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;">
  @isset($preheader)
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f4f4f5;">{{ $preheader }}</div>
  @endisset

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f4f5;">
    <tr>
      <td align="center" style="padding:32px 16px;">

        <table role="presentation" width="560" cellpadding="0" cellspacing="0" border="0" style="width:560px;max-width:100%;">
          {{-- Card --}}
          <tr>
            <td style="background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #ececec;">

              {{-- Brand header --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="background:{{ $headerColor }};padding:20px 32px;">
                    <p style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:700;letter-spacing:0.4px;">{{ $brand }}</p>
                  </td>
                </tr>
              </table>

              {{-- Body --}}
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                <tr>
                  <td style="padding:32px;font-family:Arial,Helvetica,sans-serif;color:#1a1a1a;font-size:15px;line-height:1.6;">
                    @yield('content')
                  </td>
                </tr>
              </table>

            </td>
          </tr>

          {{-- Footer --}}
          <tr>
            <td style="padding:20px 24px 0;font-family:Arial,Helvetica,sans-serif;text-align:center;font-size:12px;line-height:1.6;color:#9a9aa2;">
              @hasSection('footer')
                @yield('footer')
              @else
                <p style="margin:0;">You're receiving this email from {{ $brand }}.</p>
              @endif
            </td>
          </tr>
        </table>

      </td>
    </tr>
  </table>
</body>
</html>
