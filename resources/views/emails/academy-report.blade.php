@extends('emails.layout', [
    'brandName' => $brand['name'],
    'brandColor' => $brand['color'],
    'preheader' => "{$academy} has been reported by a student",
    'platformMail' => true,
])

{{-- Goes to the platform team, so `platformMail` is hard-coded true rather than
     read off the brand: resolveBrand(null) already guarantees the platform
     identity, and pinning it here means a future change to how brands resolve
     cannot quietly turn this into an academy-branded email. --}}

@section('content')
  @include('emails.partials.heading', [
    'title' => 'An academy has been reported',
    'subtitle' => $academy,
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    {{ $student }} ({{ $studentEmail }}) has reported <strong style="color:#111827;">{{ $academy }}</strong>.
  </p>

  <p style="margin:14px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    <strong style="color:#111827;">Reason given:</strong> {{ $categoryLabel }}
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:20px 0 0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:16px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.7;color:#374151;">
        <p style="margin:0 0 6px;font-size:12px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:#6b7280;">In their own words</p>
        <p style="margin:0;white-space:pre-line;">{{ $details }}</p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $ctaUrl, 'label' => 'Open the report queue', 'color' => $brand['color']])
  @include('emails.partials.fallback-link', ['url' => $ctaUrl, 'color' => $brand['color']])
@endsection

@section('footer')
  <p style="margin:0;">Sent to the Jorsas Tech platform team because a student reported an academy.</p>
@endsection
