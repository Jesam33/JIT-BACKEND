@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => $paragraphs[0] ?? $heading,
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', ['title' => $heading, 'subtitle' => null])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $greeting }},</p>

  @foreach ($paragraphs as $paragraph)
    <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">{{ $paragraph }}</p>
  @endforeach

  @if ($quote)
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0;background:#f7f7f8;border-radius:8px;">
      <tr>
        <td style="padding:16px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#374151;">
          <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#6b7280;">{{ $quoteLabel }}</p>
          <p style="margin:0;white-space:pre-line;">{{ $quote }}</p>
        </td>
      </tr>
    </table>
  @endif

  @if ($ctaLabel && $ctaUrl)
    @include('emails.partials.button', ['url' => $ctaUrl, 'label' => $ctaLabel, 'color' => $brand['color']])
    @include('emails.partials.fallback-link', ['url' => $ctaUrl, 'color' => $brand['color']])
  @endif
@endsection

@section('footer')
  <p style="margin:0;">{{ $footerNote }}</p>
@endsection
