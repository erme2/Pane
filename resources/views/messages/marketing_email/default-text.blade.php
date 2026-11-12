{{-- Plain-text part: not rendered as HTML, so values are printed raw (HTML escaping would break links with &). --}}
@if ($environmentLabel)[{!! $environmentLabel !!} environment: not a production email]

@endif
@if (($payload['headline'] ?? '') !== ''){!! $payload['headline'] !!}

@endif
@if (($payload['body'] ?? '') !== ''){!! $payload['body'] !!}

@endif
@if ($link){!! $link !!}

@endif
-- 
Sent by {!! $senderName !!}.
You're receiving this because you agreed to hear from {!! $senderName !!}. Unsubscribe: (coming soon)
