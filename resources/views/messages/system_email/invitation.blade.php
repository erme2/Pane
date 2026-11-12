@extends('messages.layout')

@section('content')
    <p style="margin:0 0 16px;">
        You've been invited to join <strong>{{ $payload['organization_name'] ?? '' }}</strong>@if (($payload['role'] ?? '') !== '') as {{ $payload['role'] }}@endif.
    </p>
    @if ($link)
        <p style="margin:0 0 24px;">
            <a href="{{ $link }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;font-weight:600;padding:12px 20px;border-radius:6px;">Accept the invitation</a>
        </p>
        <p style="margin:0 0 16px;font-size:13px;color:#6b7280;">Or open this link: <a href="{{ $link }}" style="color:#2563eb;word-break:break-all;">{{ $link }}</a></p>
    @endif
    @if ($expiresAt)
        <p style="margin:0 0 16px;font-size:13px;color:#6b7280;">The invitation expires on {{ $expiresAt }}.</p>
    @endif
    <p style="margin:0;font-size:13px;color:#6b7280;">If you weren't expecting this invitation, you can ignore this email.</p>
@endsection
