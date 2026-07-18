<p>New agent application received.</p>
<ul>
    <li><strong>Name:</strong> {{ $agent->name }}</li>
    <li><strong>Email:</strong> {{ $agent->email }}</li>
    <li><strong>Phone:</strong> {{ $agent->phone }}</li>
    <li><strong>Address:</strong> {{ $agent->home_address }}</li>
    <li><strong>Qualification:</strong> {{ $agent->qualification }}</li>
</ul>
<p><a href="{{ $adminUrl }}" style="background:#000;color:#fff;padding:10px 20px;text-decoration:none;border-radius:6px;display:inline-block;">Review in Admin</a></p>
