{{-- Plain-text part: not rendered as HTML, so values are printed raw (HTML escaping would break links with &). --}}
@if ($environmentLabel)[{!! $environmentLabel !!} environment: not a production email]

@endif
You've been invited to join {!! $payload['organization_name'] ?? '' !!}@if (($payload['role'] ?? '') !== '') as {!! $payload['role'] !!}@endif.

@if ($link)
Accept the invitation: {!! $link !!}

@endif
@if ($expiresAt)
The invitation expires on {!! $expiresAt !!}.
@endif
If you weren't expecting this invitation, you can ignore this email.

-- 
Sent by {!! $senderName !!}.
