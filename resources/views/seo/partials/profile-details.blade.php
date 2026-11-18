@php
    $email = filter_var($item->email ?? null, FILTER_VALIDATE_EMAIL) ? $item->email : null;
    $linkedIn = $item->linkedin_url ?? null;
    $linkedIn = is_string($linkedIn) && filter_var($linkedIn, FILTER_VALIDATE_URL)
        && in_array(strtolower((string) parse_url($linkedIn, PHP_URL_SCHEME)), ['http', 'https'], true) ? $linkedIn : null;
    $youtube = \App\Services\YouTubeProfileLink::valid($item->youtube_url ?? null) ? $item->youtube_url : null;
@endphp
<div class="seo-profile-details">
    @if ($email)<p><strong>Email:</strong> <a href="mailto:{{ $email }}">{{ $email }}</a></p>@endif
    @if (($showStoryLinks ?? true) && filled($item->description ?? null))<div><strong>{{ $labels['about'] }}</strong><p class="bio">{{ $item->description }}</p></div>@endif
    @if ($showStoryLinks ?? true)
        @if ($linkedIn)<p><a class="text-link" href="{{ $linkedIn }}" target="_blank" rel="noopener noreferrer nofollow ugc">{{ $labels['linkedin'] }} ↗</a></p>@endif
        @if ($youtube)<p><a class="text-link" href="{{ $youtube }}" target="_blank" rel="noopener noreferrer nofollow ugc">YouTube ↗</a></p>@endif
    @endif
    @if ($item->masked_phone ?? null)<p><a class="text-link seo-call" href="{{ $contactHref }}">Call · {{ $item->masked_phone }}</a></p>@endif
</div>
