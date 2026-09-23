{{-- The "Need Help?" block under the footer divider. Rendered by the layout, so
     it appears on every email; each row is dropped when its value is empty.
     Vars (all optional): $supportEmail, $supportPhone, $supportHours --}}
@php
    $supportEmail = trim((string) ($supportEmail ?? ''));
    $supportPhone = trim((string) ($supportPhone ?? ''));
    $supportHours = trim((string) ($supportHours ?? ''));
    // Resolved here rather than from the layout's scope: a partial included from
    // a section does not see the layout's variables. The helper also refuses any
    // icon name we do not actually ship, so a typo cannot emit a broken image.
    $icon = fn (string $name): ?string => \App\Support\EmailIcons::url('icon-' . $name . '.png');
    $rowStyle = 'font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.5;color:#6b7280;';
@endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;">
  <tr>
    <td style="font-family:Arial,Helvetica,sans-serif;">
      <p style="margin:0;font-size:17px;font-weight:700;line-height:1.4;color:#111827;">Need Help?</p>
      <p style="margin:6px 0 0;font-size:14px;line-height:1.6;color:#6b7280;">Our customer support team is available to assist you:</p>
    </td>
  </tr>
</table>

@if ($supportEmail !== '' || $supportPhone !== '' || $supportHours !== '')
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:14px 0 0;">
    @foreach ([
        ['icon' => 'mail',  'text' => $supportEmail, 'href' => $supportEmail !== '' ? 'mailto:' . $supportEmail : null],
        ['icon' => 'phone', 'text' => $supportPhone, 'href' => $supportPhone !== '' ? 'tel:' . preg_replace('/[^0-9+]/', '', $supportPhone) : null],
        ['icon' => 'clock', 'text' => $supportHours, 'href' => null],
    ] as $row)
      @continue($row['text'] === '')
      <tr>
        @if ($icon($row['icon']))
          <td width="26" valign="middle" style="width:26px;padding:0 10px 0 0;">
            <img src="{{ $icon($row['icon']) }}" alt="" width="16" height="16" style="display:block;width:16px;height:16px;border:0;outline:none;">
          </td>
        @endif
        <td valign="middle" style="{{ $rowStyle }}padding:4px 0;">
          @if ($row['href'])
            <a href="{{ $row['href'] }}" style="color:#6b7280;text-decoration:underline;">{{ $row['text'] }}</a>
          @else
            {{ $row['text'] }}
          @endif
        </td>
      </tr>
    @endforeach
  </table>
@endif
