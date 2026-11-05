<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $preview }}</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    <tr>
                        <td style="padding:0 4px 18px;">
                            @if ($logo)
                                <img src="{{ $logo }}" alt="{{ $agencyName }}" style="max-height:36px;max-width:180px;display:block;border:0;">
                            @else
                                <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                                    <td style="width:32px;height:32px;border-radius:8px;background:{{ $accent }};color:#ffffff;font-weight:700;font-size:15px;text-align:center;">{{ mb_strtoupper(mb_substr($agencyName, 0, 1)) }}</td>
                                    <td style="padding-left:10px;font-size:15px;font-weight:600;color:#18181b;">{{ $agencyName }}</td>
                                </tr></table>
                            @endif
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#ffffff;border:1px solid #e4e4e7;border-radius:16px;overflow:hidden;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                <tr><td style="height:4px;background:{{ $accent }};font-size:0;line-height:0;">&nbsp;</td></tr>
                                <tr>
                                    <td style="padding:28px 28px 8px;">
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:{{ $accent }};">{{ $eyebrow }}</p>
                                        <h1 style="margin:0 0 8px;font-size:21px;line-height:1.3;font-weight:700;color:#18181b;">{{ $title }}</h1>
                                        <p style="margin:0;font-size:14px;line-height:1.6;color:#52525b;">{{ $lead }}</p>
                                    </td>
                                </tr>

                                @if ($quote)
                                    <tr>
                                        <td style="padding:18px 28px 0;">
                                            <div style="border-left:3px solid #e4e4e7;padding:4px 0 4px 14px;">
                                                @if ($quoteLabel)
                                                    <p style="margin:0 0 4px;font-size:12px;font-weight:600;color:#71717a;">{{ $quoteLabel }}</p>
                                                @endif
                                                <p style="margin:0;font-size:14px;line-height:1.6;color:#3f3f46;white-space:pre-line;">{{ $quote }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                @if ($reply)
                                    <tr>
                                        <td style="padding:16px 28px 0;">
                                            <div style="background:#fafafa;border:1px solid #f0f0f2;border-radius:12px;padding:14px 16px;">
                                                @if ($replyLabel)
                                                    <p style="margin:0 0 6px;font-size:12px;font-weight:600;color:{{ $accent }};">{{ $replyLabel }}</p>
                                                @endif
                                                <p style="margin:0;font-size:14px;line-height:1.65;color:#18181b;white-space:pre-line;">{{ $reply }}</p>
                                            </div>
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding:24px 28px 28px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                                            <td style="border-radius:10px;background:{{ $accent }};">
                                                <a href="{{ $ctaUrl }}" style="display:inline-block;padding:12px 20px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">{{ $ctaLabel }} &rarr;</a>
                                            </td>
                                        </tr></table>
                                        @if ($note)
                                            <p style="margin:14px 0 0;font-size:12px;line-height:1.6;color:#71717a;">{{ $note }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 8px 0;text-align:center;font-size:12px;line-height:1.7;color:#71717a;">
                            <p style="margin:0;">{{ $footer }}</p>
                            @if ($showBranding)
                                <p style="margin:10px 0 0;font-size:11px;color:#a1a1aa;">Reportes con {{ config('app.name', 'Imagina Reports') }}</p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
