@php
    $preheader = $cohortName . ' has ended. Review who completed and issue certificates from your owner console.';
@endphp
@extends('emails.layout', ['brandName' => 'Jorsas Tech', 'preheader' => $preheader])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hello {{ $ownerName }},</p>

  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">A cohort has ended</h1>
  <p style="margin:0 0 20px;font-size:15px;line-height:1.6;color:#444;">
    {{ $cohortName }} has reached its end date. Review the students and issue their completion certificates —
    it takes one click, and each student is notified automatically.
  </p>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 22px;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:20px 22px;font-family:Arial,Helvetica,sans-serif;">
        <p style="margin:0 0 6px;font-size:17px;font-weight:700;color:#1a1a1a;">{{ $cohortName }}</p>
        @if($courseTitle)
          <p style="margin:0 0 14px;font-size:14px;line-height:1.6;color:#555;">{{ $courseTitle }}</p>
        @endif
        @if($datesLine)
          <p style="margin:0 0 4px;font-size:14px;color:#333;"><strong>Dates:</strong> {{ $datesLine }}</p>
        @endif
        <p style="margin:0;font-size:14px;color:#333;">
          <strong>Completed:</strong> {{ $completedCount }} of {{ $studentCount }} student{{ $studentCount === 1 ? '' : 's' }}
        </p>
      </td>
    </tr>
  </table>

  @include('emails.partials.button', ['url' => $reviewUrl, 'label' => 'Review certificates'])
  @include('emails.partials.fallback-link', ['url' => $reviewUrl])

  <p style="margin:22px 0 0;font-size:13px;line-height:1.6;color:#888;">
    Students who passed all their module tasks are pre-selected; you can include anyone else before issuing.
    Each certificate carries the student's name and the cohort's start and end dates.
  </p>
@endsection

@section('footer')
  <p style="margin:0;">You're receiving this because you own an academy on Jorsas Tech.</p>
@endsection
