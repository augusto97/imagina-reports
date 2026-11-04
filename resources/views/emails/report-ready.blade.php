@php
    $title = __('report.ready_title', ['period' => $period]);
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $title }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Inter,-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
    {{-- Inbox preview line --}}
    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">{{ $site }} · {{ $title }}@if ($score !== null) · {{ __('report.ready_health') }} {{ $score }}/100 @endif</div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f5f7;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">
                    {{-- Agency brand --}}
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
                                        <p style="margin:0 0 6px;font-size:12px;font-weight:600;letter-spacing:.08em;text-transform:uppercase;color:{{ $accent }};">{{ $site }}</p>
                                        <h1 style="margin:0 0 10px;font-size:22px;line-height:1.3;font-weight:700;color:#18181b;">{{ $title }}</h1>
                                        <p style="margin:0;font-size:14px;line-height:1.6;color:#52525b;">{{ __('report.ready_lead', ['site' => $site]) }}</p>
                                    </td>
                                </tr>

                                @if ($score !== null)
                                    <tr>
                                        <td style="padding:20px 28px 4px;">
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border:1px solid #f0f0f2;border-radius:12px;">
                                                <tr>
                                                    <td style="padding:16px 18px;font-size:13px;color:#52525b;">{{ __('report.ready_health') }}</td>
                                                    <td align="right" style="padding:16px 18px;">
                                                        <span style="font-size:26px;font-weight:700;color:{{ $scoreColor }};">{{ $score }}</span><span style="font-size:13px;color:#a1a1aa;">/100</span>
                                                        @if ($verdict)
                                                            <span style="display:inline-block;margin-left:8px;padding:3px 10px;border-radius:999px;background:{{ $scoreColor }}1a;color:{{ $scoreColor }};font-size:12px;font-weight:600;vertical-align:middle;">{{ $verdict }}</span>
                                                        @endif
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif

                                @if (count($highlights) > 0)
                                    <tr>
                                        <td style="padding:16px 28px 4px;">
                                            <p style="margin:0 0 10px;font-size:12px;font-weight:600;color:#71717a;">{{ __('report.ready_highlights') }}</p>
                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                <tr>
                                                    @foreach ($highlights as $item)
                                                        <td width="{{ (int) floor(100 / count($highlights)) }}%" valign="top" style="padding:{{ $loop->first ? '0 6px 0 0' : ($loop->last ? '0 0 0 6px' : '0 6px') }};">
                                                            <div style="border:1px solid #e4e4e7;border-radius:12px;padding:14px;">
                                                                <p style="margin:0 0 6px;font-size:12px;color:#71717a;line-height:1.35;">{{ $item['label'] }}</p>
                                                                <p style="margin:0;font-size:20px;font-weight:700;color:#18181b;white-space:nowrap;">{{ $item['value'] }}</p>
                                                                @if ($item['change'] !== null)
                                                                    <p style="margin:6px 0 0;font-size:12px;font-weight:600;color:{{ $item['good'] === false ? '#dc2626' : '#059669' }};">{{ $item['change'] }} <span style="font-weight:400;color:#a1a1aa;">{{ __('report.ready_vs') }}</span></p>
                                                                @endif
                                                            </div>
                                                        </td>
                                                    @endforeach
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>
                                @endif

                                @if ($summary !== '')
                                    <tr>
                                        <td style="padding:18px 28px 0;">
                                            <p style="margin:0;font-size:14px;line-height:1.65;color:#3f3f46;white-space:pre-line;">{{ $summary }}</p>
                                        </td>
                                    </tr>
                                @endif

                                <tr>
                                    <td style="padding:24px 28px 28px;">
                                        <table role="presentation" cellpadding="0" cellspacing="0"><tr>
                                            <td style="border-radius:10px;background:{{ $accent }};">
                                                <a href="{{ $portalUrl }}" style="display:inline-block;padding:13px 22px;font-size:14px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">{{ __('report.ready_cta_full') }} &rarr;</a>
                                            </td>
                                        </tr></table>
                                        @if ($hasPdf)
                                            <p style="margin:14px 0 0;font-size:12px;color:#71717a;">{{ __('report.ready_pdf_note') }}</p>
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:20px 8px 0;text-align:center;font-size:12px;line-height:1.7;color:#71717a;">
                            <p style="margin:0;">{{ __('report.ready_reply') }}</p>
                            <p style="margin:4px 0 0;">
                                {{ __('report.ready_sent_by', ['agency' => $agencyName]) }}
                                @if (! empty($contact['website'])) · <a href="{{ $contact['website'] }}" style="color:#71717a;">{{ preg_replace('#^https?://(www\.)?#', '', rtrim($contact['website'], '/')) }}</a>@endif
                                @if (! empty($contact['phone'])) · {{ $contact['phone'] }}@endif
                            </p>
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
