<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Nearby Teacher weekly owner report</title></head>
<body style="margin:0;background:#f5f2ec;font-family:Arial,Helvetica,sans-serif;color:#25221f;">
<div style="max-width:780px;margin:0 auto;padding:28px 16px 36px;">
    <div style="height:7px;background:#c92520;border-radius:12px 12px 0 0;"></div>
    <main style="padding:30px 32px;background:#fffdfa;border:1px solid #e9e2d7;border-top:0;border-radius:0 0 12px 12px;">
        <p style="margin:0 0 8px;color:#c92520;font-size:12px;font-weight:bold;letter-spacing:2px;text-transform:uppercase;">BSL Team · Every Monday</p>
        <h1 style="margin:0;color:#211f1c;font-size:32px;line-height:1.2;">The weekly owner report</h1>
        <p style="margin:9px 0 24px;color:#766e64;font-size:14px;">{{ $lastWeek['from']->format('j M') }} – {{ $lastWeek['to']->format('j M Y') }}, compared with {{ $weekBefore['from']->format('j M') }} – {{ $weekBefore['to']->format('j M Y') }}</p>

        <div style="margin-bottom:19px;padding:13px 15px;border-radius:9px;background:#fff4e5;color:#52473a;font-size:13px;line-height:1.7;">
            <strong>{{ number_format($snapshot['learners']) }} learners</strong> ·
            {{ number_format($snapshot['teacher_pages']) }} teacher pages ·
            {{ number_format($snapshot['institute_pages']) }} institute pages ·
            {{ number_format($snapshot['districts']) }} districts ·
            {{ number_format($snapshot['lessons_accepted']) }} lessons accepted so far
        </div>

        <div style="overflow-x:auto;border:1px solid #eee8df;border-radius:10px;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
                <thead><tr style="background:#f5f0e8;text-align:left;">
                    <th style="padding:10px 12px;">This week</th><th style="padding:10px 8px;text-align:right;">Last week</th><th style="padding:10px 8px;text-align:right;">Week before</th><th style="padding:10px 12px;text-align:right;">Change</th>
                </tr></thead>
                <tbody>
                @foreach ($lastWeek['metrics'] as $key => $metric)
                    @php
                        $current = $metric['value'];
                        $previous = $weekBefore['metrics'][$key]['value'];
                        $difference = ($current === null || $previous === null) ? null : $current - $previous;
                        $changePercent = ($difference === null || $previous == 0) ? null : round(abs($difference) * 100 / abs($previous));
                        $formatValue = static function ($value, string $kind): string {
                            if ($value === null) return '—';
                            if ($kind === 'percent') return number_format($value, 1).'%';
                            if ($kind === 'rating') return number_format($value, 1).' / 5';
                            return number_format($value);
                        };
                    @endphp
                    <tr style="border-top:1px solid #f0ece6;">
                        <td style="padding:8px 12px;">{{ $metric['label'] }}</td>
                        <td style="padding:8px;text-align:right;font-weight:bold;">{{ $formatValue($current, $metric['kind']) }}</td>
                        <td style="padding:8px;text-align:right;color:#776f65;">{{ $formatValue($previous, $metric['kind']) }}</td>
                        <td style="padding:8px 12px;text-align:right;white-space:nowrap;color:{{ $difference === null || $difference === 0 ? '#77716b' : ($difference > 0 ? '#39824d' : '#c02b26') }};">
                            @if ($difference === null) — @elseif ($difference === 0) same @else {{ $difference > 0 ? '▲' : '▼' }} {{ $changePercent === null ? number_format(abs($difference), 1) : $changePercent.'%' }} @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <h2 style="margin:22px 0 8px;font-size:17px;">Waiting for your team</h2>
        <ul style="margin:0;padding-left:20px;color:#514b44;font-size:13px;line-height:1.9;">
            <li>Safety reports to review: <strong>{{ number_format($queues['safety_reports']) }}</strong></li>
            <li>Photos to approve: <strong>{{ number_format($queues['photos']) }}</strong></li>
            <li>Possible duplicate teacher profiles: <strong>{{ number_format($queues['possible_duplicates']) }}</strong></li>
            <li>New job, franchise and support leads: <strong>{{ number_format($queues['new_leads']) }}</strong></li>
        </ul>
        <p style="margin:20px 0 0;"><a href="{{ rtrim(config('app.url'), '/').'/admin/dashboard' }}" style="display:inline-block;padding:12px 18px;border-radius:7px;background:#c92520;color:#fff;text-decoration:none;font-size:13px;font-weight:bold;">Open admin dashboard</a></p>
        <p style="margin:24px 0 0;color:#82796f;font-size:11px;">Nearby Teacher by BSL · Generated {{ $generatedAt->format('j M Y, g:i A') }} ({{ config('app.timezone') }})</p>
    </main>
</div>
</body>
</html>
