@extends('emails.layout', ['brandName' => 'Jorsas Institute of Technology', 'preheader' => 'A new student registration was submitted.'])

@section('content')
  <h1 style="margin:0 0 16px;font-size:21px;line-height:1.3;color:#1a1a1a;font-weight:700;">New training registration</h1>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 20px;background:#f7f7f8;border:1px solid #ececec;border-radius:12px;">
    <tr>
      <td style="padding:18px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.9;color:#1a1a1a;">
        <span style="color:#888;">Name</span> &nbsp;<strong>{{ $registration->first_name }} {{ $registration->last_name }}</strong><br>
        <span style="color:#888;">Email</span> &nbsp;<strong>{{ $registration->email }}</strong><br>
        <span style="color:#888;">Phone</span> &nbsp;<strong>{{ $registration->phone_number }}</strong><br>
        <span style="color:#888;">WhatsApp</span> &nbsp;<strong>{{ $registration->whatsapp }}</strong><br>
        <span style="color:#888;">Date of birth</span> &nbsp;<strong>{{ optional($registration->date_of_birth)->format('Y-m-d') }}</strong><br>
        <span style="color:#888;">Qualification</span> &nbsp;<strong>{{ $registration->qualification_level }}</strong><br>
        <span style="color:#888;">Course</span> &nbsp;<strong>{{ $registration->course_name }}</strong><br>
        <span style="color:#888;">Learning mode</span> &nbsp;<strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live classes' }}</strong>
      </td>
    </tr>
  </table>

  <p style="margin:0;font-size:14px;color:#444;">
    Status: <strong>{{ ucfirst($registration->status) }}</strong>
  </p>
@endsection

@section('footer')
  <p style="margin:0;">Jorsas Institute of Technology</p>
@endsection
