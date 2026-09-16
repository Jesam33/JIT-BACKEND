@php
    $preheader = $daysLeft <= 1
        ? $academyName . ' pauses tomorrow. Renew your ' . $planName . ' plan to keep it running.'
        : $academyName . ' is in its grace window. Renew your ' . $planName . ' plan to keep it running.';
@endphp
@extends('emails.layout', ['brandName' => 'Jorsas Tech', 'preheader' => $preheader])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $ownerName }},</p>

  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">
    Your {{ $planName }} plan has ended
  </h1>
  <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#444;">
    {{ $academyName }} is still open, but the paid period for your {{ $planName }} plan finished on
    {{ $endedOn }}. Renew it to keep your courses, students, live classes and chat running without
    interruption.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 6px;font-size:17px;font-weight:700;color:#1a1a1a;">{{ $academyName }}</p>
        <p style="margin:0 0 14px;font-size:14px;color:#555;">{{ $planName }} plan</p>
        <p style="margin:0 0 4px;font-size:14px;color:#333;"><strong>Ended:</strong> {{ $endedOn }}</p>
        <p style="margin:0;font-size:14px;color:#333;">
          <strong>Access pauses:</strong> {{ $freezesOn }}
          @if($daysLeft > 0)
            ({{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }} left)
          @else
            (today is the last day)
          @endif
        </p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $billingUrl, 'label' => 'Reinstate my plan'])
  @include('emails.partials.fallback-link', ['url' => $billingUrl])

  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    Your students keep their access and nothing is deleted — only your owner dashboard pauses until the
    plan is reinstated. You'll get this reminder each day until then.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
