{{-- Solid hairline rule between blocks. A bare <hr> renders inconsistently
     across clients, so this is a one-cell table with a top border. Usage:
     @include('emails.partials.divider')
     Optional: ['space' => '32px'] to change the vertical margin. --}}
@php $space = trim((string) ($space ?? '')) !== '' ? $space : '32px'; @endphp
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:{{ $space }} 0;">
  <tr>
    <td style="border-top:1px solid #e5e7eb;height:1px;font-size:0;line-height:0;">&nbsp;</td>
  </tr>
</table>
