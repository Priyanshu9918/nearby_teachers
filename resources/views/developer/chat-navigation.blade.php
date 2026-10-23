<details class="socket-navigation" id="chat-navigation">
    <summary><span class="nav-icon" aria-hidden="true">◉</span><span>WebSockets</span><span class="socket-nav-chevron" aria-hidden="true">⌄</span></summary>
    <div class="socket-nav-links" id="chat-navigation-links">
        <a href="#realtime/overview" data-tab="realtime" data-chat-view="overview"><span aria-hidden="true">⌂</span>Getting started</a>
        <a href="#realtime/connection" data-tab="realtime" data-chat-view="connection"><span aria-hidden="true">01</span>Connect &amp; verify</a>
        <a href="#realtime/ping" data-tab="realtime" data-chat-view="ping"><span aria-hidden="true">02</span>Ping &amp; heartbeat</a>
        <a href="#realtime/conversations" data-tab="realtime" data-chat-view="conversations"><span aria-hidden="true">03</span>Conversations</a>
        <a href="#realtime/send" data-tab="realtime" data-chat-view="send"><span aria-hidden="true">04</span>Send a message</a>
        <a href="#realtime/receive" data-tab="realtime" data-chat-view="receive"><span aria-hidden="true">05</span>Receive messages</a>
        <details class="socket-event-navigation"><summary>Chat events · 13</summary>
            @foreach (config('chat_events') as $event => $contract)
                <a href="#realtime/events/{{ $event }}" data-tab="realtime" data-chat-view="events" data-chat-event="{{ $event }}"><span aria-hidden="true">{{ $contract['direction'] === 'server' ? '↓' : '↗' }}</span>{{ $contract['label'] }}</a>
            @endforeach
        </details>
        <a href="#realtime/history" data-tab="realtime" data-chat-view="history"><span aria-hidden="true">06</span>Saved history</a>
        <a href="#realtime/session" data-tab="realtime" data-chat-view="session"><span aria-hidden="true">07</span>Session &amp; reconnect</a>
        <a href="#realtime/end" data-tab="realtime" data-chat-view="end"><span aria-hidden="true">08</span>Leave &amp; end test</a>
        <a href="#realtime/full" data-tab="realtime" data-chat-view="full"><span aria-hidden="true">✓</span>Run full check</a>
    </div>
</details>
