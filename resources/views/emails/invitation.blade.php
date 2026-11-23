{{ $inviterName }} invited you to Sweegly as {{ $role === 'admin' ? 'an administrator' : 'a user' }}.

Accept the invitation and sign up:
{!! $invitationUrl !!}

The link works once and expires on {{ $expiresAt }}. If you did not expect this invitation, you can ignore this email.
