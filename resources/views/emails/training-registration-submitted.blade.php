@extends('emails.layout', [
    'brandName' => 'Jorsas Institute of Technology',
    'brandColor' => '#ed180d',
    'preheader' => 'A new student registration was submitted.',
    // An internal alert to the platform's own admin address.
    'platformMail' => true,
])

@section('content')
  @include('emails.partials.heading', [
    'title' => 'New training registration',
    'subtitle' => 'A student has just registered for a course',
  ])

  <div style="height:26px;font-size:0;line-height:0;">&nbsp;</div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0;background:#f7f7f8;border-radius:8px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#111827;">
        <span style="color:#6b7280;">Name</span> &nbsp;<strong>{{ $registration->first_name }} {{ $registration->last_name }}</strong><br>
        <span style="color:#6b7280;">Email</span> &nbsp;<strong>{{ $registration->email }}</strong><br>
        <span style="color:#6b7280;">Phone</span> &nbsp;<strong>{{ $registration->phone_number }}</strong><br>
        <span style="color:#6b7280;">WhatsApp</span> &nbsp;<strong>{{ $registration->whatsapp }}</strong><br>
        <span style="color:#6b7280;">Date of birth</span> &nbsp;<strong>{{ optional($registration->date_of_birth)->format('Y-m-d') }}</strong><br>
        <span style="color:#6b7280;">Qualification</span> &nbsp;<strong>{{ $registration->qualification_level }}</strong><br>
        <span style="color:#6b7280;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#6b7280;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
      </td>
    </tr>
  </table>

  <p style="margin:20px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.6;color:#6b7280;">
    Status: <strong style="color:#111827;">{{ ucfirst($registration->status) }}</strong>
  </p>
@endsection

@section('footer')
  <p style="margin:0;">Jorsas Institute of Technology</p>
@endsection
