<p>{{ $inviterName }} invited you to Sweegly as {{ $role === 'admin' ? 'an administrator' : 'a user' }}.</p>
<p><a href="{{ $invitationUrl }}">Accept the invitation and sign up</a></p>
<p>The link works once and expires on {{ $expiresAt }}. If you did not expect this invitation, you can ignore this email.</p>
