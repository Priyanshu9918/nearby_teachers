@extends('site.layout')

@section('title', data_get($t, 'referral.landingTitle', 'Your referral invitation'))
@section('description', data_get($t, 'referral.landingIntro', 'Download the app, complete registration, and apply your referral code.'))

@section('content')
<main class="center">
    <div class="box">
        <h1>{{ data_get($t, 'referral.landingTitle', 'Your referral invitation') }}</h1>

        @if ($referralValid)
            <p class="sub">{{ data_get($t, 'referral.landingIntro', 'Download the app, complete registration, and apply your referral code.') }}</p>
            <div class="referral-code-card">
                <p class="meta">{{ data_get($t, 'referral.codeLabel', 'Your referral code') }}</p>
                <code class="referral-code">{{ $ref }}</code>
            </div>
            <ol class="referral-steps">
                <li>{{ data_get($t, 'referral.stepDownload', 'Download the app using the button below.') }}</li>
                <li>{{ data_get($t, 'referral.stepRegister', 'Create your account and complete registration in the app.') }}</li>
                <li>{{ data_get($t, 'referral.stepRedeem', 'Open Referrals → Redeem referral in the app and apply this code.') }}</li>
            </ol>
            <p class="meta">{{ data_get($t, 'referral.pointMessage', 'Your friend receives one point after you successfully apply the code.') }}</p>
        @else
            <p class="sub">{{ data_get($t, 'referral.invalidCode', 'This referral code is not valid. You can still download the app.') }}</p>
        @endif

        @if (filled($downloadUrl))
            <a class="btn referral-download" href="{{ $downloadUrl }}">{{ data_get($t, 'referral.downloadButton', data_get($t, 'common.download', 'Download the app')) }}@if($downloadStore) — {{ $downloadStore }}@endif</a>
        @else
            <span class="btn disabled referral-download">{{ data_get($t, 'referral.downloadUnavailable', 'The app download link is not available yet.') }}</span>
        @endif


    </div>
</main>
<style>
    .referral-code-card{background:var(--app-primary-soft);border-radius:12px;padding:14px;margin:0 0 20px}
    .referral-code{display:block;color:var(--app-primary);font-size:26px;font-weight:700;letter-spacing:.14em;margin-top:6px}
    .referral-steps{text-align:start;color:var(--app-text-primary);font-size:14px;line-height:1.6;padding-inline-start:24px;margin:0 0 18px}
    .referral-steps li{padding:4px 0 4px 3px}
    .referral-download{display:block;margin-top:18px}
</style>
@endsection
