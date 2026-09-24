@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => $paragraphs[0] ?? $heading,
    'platformMail' => (bool) ($brand['is_platform'] ?? false),
])

@section('content')
  @include('emails.partials.heading', [
    'title' => $heading,
    'subtitle' => $subtitle,
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $greeting }},</p>

  @foreach ($paragraphs as $paragraph)
    <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">{{ $paragraph }}</p>
  @endforeach

  @if ($notice)
    <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

    @include('emails.partials.notice', [
      'icon' => 'info',
      'title' => $notice['title'],
      'body' => $notice['body'],
    ])
  @endif

  @if ($ctaLabel && $ctaUrl)
    @include('emails.partials.button', ['url' => $ctaUrl, 'label' => $ctaLabel, 'color' => $brand['color']])
    @include('emails.partials.fallback-link', ['url' => $ctaUrl, 'color' => $brand['color']])
  @endif
@endsection

@section('footer')
  <p style="margin:0;">{{ $footerNote }}</p>
@endsection
