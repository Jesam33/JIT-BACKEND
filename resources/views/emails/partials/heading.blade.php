{{-- The centred title + grey subtitle that sit under the header mark. Kept in a
     partial so every email's heading is the same size and spacing. Usage:
     @include('emails.partials.heading', [
       'title' => 'Reset your password',
       'subtitle' => 'We received a request to reset it',   // optional
     ]) --}}
@php
    $title = trim((string) ($title ?? ''));
    $subtitle = trim((string) ($subtitle ?? ''));
@endphp
<h1 style="margin:30px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:26px;line-height:1.3;font-weight:700;color:#111827;text-align:center;">{{ $title }}</h1>
@if ($subtitle !== '')
  <p style="margin:8px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#6b7280;text-align:center;">{{ $subtitle }}</p>
@endif
