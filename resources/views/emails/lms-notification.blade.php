{{-- Notification / announcement email. Rendered by the lms:send-notification-emails
     sweep. {{ }} escaping is intentional — titles/bodies are user-entered. --}}
@extends('emails.layout', ['brandName' => $instituteName, 'brandColor' => $instituteColor ?? null, 'preheader' => $notifTitle])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hi {{ $greetingName }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">{{ $notifTitle }}</h1>
  @if(trim($notifBody) !== '')
    <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">{{ $notifBody }}</p>
  @endif
  @include('emails.partials.button', ['url' => $actionUrl, 'label' => $actionLabel, 'color' => $instituteColor ?? null])
  @include('emails.partials.fallback-link', ['url' => $actionUrl, 'color' => $instituteColor ?? null])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you have an account on {{ $instituteName }}.</p>
@endsection
