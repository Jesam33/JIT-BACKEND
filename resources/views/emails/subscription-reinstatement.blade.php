@php
    $accent = '#ed180d';
    $preheader = $daysLeft <= 1
        ? $academyName . ' pauses tomorrow. Renew your ' . $planName . ' plan to keep it running.'
        : $academyName . ' is in its grace window. Renew your ' . $planName . ' plan to keep it running.';
@endphp
@extends('emails.layout', [
    'brandName' => 'Jorsas Tech',
    'brandColor' => $accent,
    'preheader' => $preheader,
    // Jorsas is the sender: this is our own billing notice to an academy owner.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'Your ' . $planName . ' plan has ended',
    'subtitle' => 'Renew to keep ' . $academyName . ' running',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $ownerName }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    The paid period for your {{ $planName }} plan finished on {{ $endedOn }}. Renew it to keep your
    courses, students, live classes and chat running without interruption.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0;font-size:17px;font-weight:700;line-height:1.4;color:#111827;">{{ $academyName }}</p>
        <p style="margin:4px 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">{{ $planName }} plan</p>
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">Ended:</strong> {{ $endedOn }}
        </p>
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">Access pauses:</strong> {{ $freezesOn }}
          @if($daysLeft > 0)
            ({{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} left)
          @else
            (today is the last day)
          @endif
        </p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $billingUrl, 'label' => 'Reinstate my plan', 'color' => $accent])
  @include('emails.partials.fallback-link', ['url' => $billingUrl, 'color' => $accent])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'clock',
    'title' => 'Nothing is deleted',
    'body' => 'Your students keep their access and nothing is removed. Only your owner dashboard pauses until the plan is reinstated. You will get this reminder each day until then.',
  ])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
