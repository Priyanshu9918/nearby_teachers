@extends('site.layout')

@section('title', str_replace('{city}', $city, data_get($t, 'website.cityFeedTitle')))
@section('description', str_replace('{city}', $city, data_get($t, 'website.feedSubtitle')))

@section('content')
<main class="wrap">
    <h1>{{ str_replace('{city}', $city, data_get($t, 'website.cityFeedTitle')) }}</h1>
    <p class="sub">{{ str_replace('{city}', $city, data_get($t, 'website.feedSubtitle')) }}
        <a class="text-link" href="{{ route('website.city', ['city' => $city]) }}">{{ data_get($t, 'website.seeAllHere') }} →</a>
    </p>
    @if ($posts->isEmpty())
        <p class="empty">{{ str_replace('{city}', $city, data_get($t, 'website.noPostsYet')) }}</p>
    @else
        @foreach ($posts as $post)
            <article class="post">
                @if ($post->post_type === 'request')<span class="type-tag">{{ data_get($t, 'feed.lookingFor') }}</span>@endif
                <div class="post-top">
                    <div class="avatar">{{ collect(preg_split('/\s+/', trim($post->author_name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('') }}</div>
                    <div><p class="name">{{ $post->author_name }}</p><p class="meta">{{ \Illuminate\Support\Carbon::parse($post->created_at)->locale($lang)->translatedFormat('M j, Y') }}</p></div>
                </div>
                <p class="bio">{{ $post->body }}</p>
                <div class="stats"><span>♡ {{ $post->like_count }}</span><span>💬 {{ $post->comment_count }}</span></div>
            </article>
        @endforeach
    @endif
    <section class="card" style="text-align:center;margin-top:24px">
        <p>{{ data_get($t, 'website.replyLikePost') }}</p>
        <a class="btn" href="{{ $downloadHref }}">{{ data_get($t, 'website.getApp') }}</a>
    </section>
</main>
@endsection
