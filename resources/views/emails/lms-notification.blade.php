@php
    $accent = trim((string) ($instituteColor ?? '')) !== '' ? $instituteColor : '#ed180d';
@endphp
@extends('emails.layout', [
    'brandName' => $instituteName,
    'brandColor' => $accent,
    'preheader' => $notifTitle,
    'platformMail' => $isPlatformMail ?? false,
])

@section('content')
  @include('emails.partials.heading', ['title' => $notifTitle])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $greetingName }},</p>

  @if(trim($notifBody) !== '')
    <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">{{ $notifBody }}</p>
  @endif

  @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel, 'color' => $accent])
  @include('emails.partials.fallback-link', ['url' => $actionUrl, 'color' => $accent])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you have an account on {{ $instituteName }}.</p>
@endsection
