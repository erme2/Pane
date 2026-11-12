@extends('messages.layout')

@section('content')
    @if (($payload['headline'] ?? '') !== '')
        <p style="margin:0 0 12px;font-size:17px;font-weight:700;">{{ $payload['headline'] }}</p>
    @endif
    @if (($payload['body'] ?? '') !== '')
        <p style="margin:0 0 16px;">{{ $payload['body'] }}</p>
    @endif
    @if ($link)
        <p style="margin:0 0 16px;"><a href="{{ $link }}" style="color:#2563eb;">{{ $payload['link_text'] ?? $link }}</a></p>
    @endif
@endsection

@section('footer')
    <br>You're receiving this because you agreed to hear from {{ $senderName }}.
    {{-- Placeholder until opt-out exists: replace with the real unsubscribe link. --}}
    <span data-unsubscribe>Unsubscribe</span>
@endsection
