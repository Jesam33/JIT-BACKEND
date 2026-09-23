{{-- The centred social row. Included by the layout, never by a template, so
     every email gets it. Links come from config('saas.social_links') so an
     account can be corrected without touching a view, and an empty entry simply
     drops that icon. Icons are hosted PNGs: Gmail strips inline <svg>. --}}
@php
    $socialLinks = collect((array) config('saas.social_links', []))
        ->map(fn ($url) => trim((string) $url))
        ->filter()
        ->all();

    $iconsBase = \App\Support\EmailIcons::base();

    $socialMarks = [
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'linkedin' => 'LinkedIn',
        'whatsapp' => 'WhatsApp',
    ];

    // Only networks that are both configured and have an icon drawn for them.
    $socialRow = $iconsBase === '' ? [] : array_intersect_key($socialMarks, $socialLinks);
@endphp
@if ($socialRow)
  <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:26px auto 0;">
    <tr>
      @foreach ($socialRow as $network => $label)
        @if (! $loop->first)
          <td width="14" style="width:14px;font-size:0;line-height:0;">&nbsp;</td>
        @endif
        <td align="center" valign="middle" style="width:20px;">
          <a href="{{ $socialLinks[$network] }}" target="_blank" title="{{ $label }}" style="text-decoration:none;border:0;">
            <img src="{{ $iconsBase }}/social-{{ $network }}.png" alt="{{ $label }}" width="20" height="20" style="display:block;width:20px;height:20px;border:0;outline:none;text-decoration:none;">
          </a>
        </td>
      @endforeach
    </tr>
  </table>
@endif
