<h2>Registration Approved</h2>
<p>Hi {{ $registration->first_name }},</p>
<p>Your registration to <strong>JORSAS INSTITUE OF TECHNOLOGY</strong> has been approved.</p>
<p>Use the link below to continue to the LMS and complete your account setup:</p>
<p><a href="{{ $lmsLink }}">Go to LMS</a></p>
<p>Your email: <strong>{{ $registration->email }}</strong></p>
<p>Course: <strong>{{ $registration->course_name }}</strong></p>
<p>Approved course price: <strong>${{ number_format((float) $registration->course_price, 2) }}</strong></p>
<p>Learning mode: <strong>{{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live Classes' }}</strong></p>
