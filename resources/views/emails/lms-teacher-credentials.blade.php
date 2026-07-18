<h2>LMS Staff Account Created</h2>
<p>Hello {{ $teacher->name }},</p>
<p>Your teacher account has been created on Jorsas LMS.</p>
<ul>
    <li><strong>Email:</strong> {{ $teacher->email }}</li>
    <li><strong>Password:</strong> {{ $plainPassword }}</li>
</ul>
<p>Please log in and change your password as soon as possible.</p>
