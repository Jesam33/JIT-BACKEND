@php
    $accent = '#ed180d';
    $preheader = $cohortName . ' has ended. Review who completed and issue certificates from your owner console.';
@endphp
@extends('emails.layout', [
    'brandName' => 'Jorsas Tech',
    'brandColor' => $accent,
    'preheader' => $preheader,
    // A platform feature notice to an academy owner, sent by Jorsas.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'A cohort has ended',
    'subtitle' => 'Review the students and issue their certificates',
  ])

  <p style="margin:28px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#111827;">Hello {{ $ownerName }},</p>

  <p style="margin:12px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#374151;">
    {{ $cohortName }} has reached its end date. Review the students and issue their completion
    certificates. It takes one click, and each student is notified automatically.
  </p>

  <div style="height:24px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0;font-size:17px;font-weight:700;line-height:1.4;color:#111827;">{{ $cohortName }}</p>
        @if($courseTitle)
          <p style="margin:4px 0 16px;font-size:14px;line-height:1.6;color:#6b7280;">{{ $courseTitle }}</p>
        @endif
        @if($datesLine)
          <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
            <strong style="color:#111827;">Dates:</strong> {{ $datesLine }}
          </p>
        @endif
        <p style="margin:0;font-size:14px;line-height:1.8;color:#6b7280;">
          <strong style="color:#111827;">Completed:</strong> {{ $completedCount }} of {{ $studentCount }} student{{ $studentCount === 1 ? '' : 's' }}
        </p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $reviewUrl, 'label' => 'Review certificates', 'color' => $accent])
  @include('emails.partials.fallback-link', ['url' => $reviewUrl, 'color' => $accent])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  @include('emails.partials.notice', [
    'icon' => 'info',
    'title' => 'Who is pre-selected',
    'body' => 'Students who passed all their module tasks are pre-selected; you can include anyone else before issuing. Each certificate carries the student\'s name and the cohort\'s start and end dates.',
  ])
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
