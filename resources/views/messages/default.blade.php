@extends('messages.layout')

@section('content')
    @if (($payload['body'] ?? '') !== '')
        <p style="margin:0 0 16px;">{{ $payload['body'] }}</p>
    @endif
    @if ($link)
        <p style="margin:0 0 16px;"><a href="{{ $link }}" style="color:#2563eb;">{{ $link }}</a></p>
    @endif
@endsection
