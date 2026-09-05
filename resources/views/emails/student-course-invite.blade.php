@extends('emails.layout', ['brandName' => $brand['name'], 'brandColor' => $brand['color'], 'preheader' => ($requiresPayment ? 'Complete your enrolment in ' : 'Set up your account to join ') . $registration->course_name])

@section('content')
  <p style="margin:0 0 18px;font-size:15px;color:#555;">Hi {{ $registration->first_name ?: 'there' }},</p>
  <h1 style="margin:0 0 12px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">You've been invited to join {{ $registration->course_name }}</h1>

  @if ($requiresPayment)
    <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
      {{ $brand['name'] }} has invited you to enrol in <strong>{{ $registration->course_name }}</strong>.
      Complete your enrolment below to secure your place — you'll set your password right after payment.
    </p>
    @include('emails.partials.button', ['url' => $link, 'label' => 'Complete enrolment & pay', 'color' => $brand['color']])
    @include('emails.partials.fallback-link', ['url' => $link, 'color' => $brand['color']])
  @else
    <p style="margin:0 0 24px;font-size:15px;line-height:1.6;color:#444;">
      {{ $brand['name'] }} has invited you to join <strong>{{ $registration->course_name }}</strong>.
      Set up your account below to get started — your place is already reserved.
    </p>
    @include('emails.partials.button', ['url' => $link, 'label' => 'Set up my account', 'color' => $brand['color']])
    @include('emails.partials.fallback-link', ['url' => $link, 'color' => $brand['color']])
  @endif

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0 0;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#888;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
        @if ($requiresPayment)
          <br><span style="color:#888;">Fee</span> &nbsp;<strong>{{ $priceDisplay }}</strong>
        @endif
      </td>
    </tr>
  </table>
@endsection

@section('footer')
  <p style="margin:0;">{{ $brand['name'] }}</p>
@endsection
