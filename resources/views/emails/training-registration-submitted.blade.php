<h2>New Training Registration</h2>
<p>A new student registration was submitted.</p>
<ul>
    <li><strong>Name:</strong> {{ $registration->first_name }} {{ $registration->last_name }}</li>
    <li><strong>Email:</strong> {{ $registration->email }}</li>
    <li><strong>Phone:</strong> {{ $registration->phone_number }}</li>
    <li><strong>WhatsApp:</strong> {{ $registration->whatsapp }}</li>
    <li><strong>Date of Birth:</strong> {{ optional($registration->date_of_birth)->format('Y-m-d') }}</li>
    <li><strong>Qualification:</strong> {{ $registration->qualification_level }}</li>
    <li><strong>Course:</strong> {{ $registration->course_name }}</li>
    <li><strong>Learning Mode:</strong> {{ $registration->learning_mode === 'pre_recorded' ? 'Pre-recorded' : 'Live Classes' }}</li>
</ul>
<p>Status: <strong>{{ ucfirst($registration->status) }}</strong></p>
