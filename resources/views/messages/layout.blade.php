{{-- Shared email layout. HTML templates escape every value with double curly braces; raw output is not allowed here. --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:8px;">
                @if ($environmentLabel)
                    <tr>
                        <td style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:600;text-align:center;padding:6px;border-radius:8px 8px 0 0;">
                            {{ $environmentLabel }} environment: not a production email
                        </td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:24px 28px 8px;font-size:18px;font-weight:700;">{{ $senderName }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 28px 24px;font-size:15px;line-height:1.55;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding:16px 28px 24px;border-top:1px solid #e5e7eb;font-size:12px;color:#6b7280;line-height:1.5;">
                        Sent by {{ $senderName }}.
                        @yield('footer')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
