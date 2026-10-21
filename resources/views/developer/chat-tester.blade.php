<div class="panel" style="margin-bottom:16px">
    <div class="panel-head"><div><h2>Live chat tester</h2><p>Use two tabs with different participant tokens to test delivery.</p></div><span class="category-badge" id="chat-status" role="status">Disconnected</span></div>
    <div class="panel-body" id="chat-tester">
        <div class="guide-grid">
            <div class="field"><label for="chat-url">Public Socket.IO origin</label><input class="control" id="chat-url" type="url" placeholder="https://chat.example.com"><p class="hint">Use the public host, or a directly reachable Node port. This can differ from the REST URL.</p></div>
            <div class="field"><label for="chat-path">Socket.IO path</label><input class="control" id="chat-path" value="/socket.io/" autocomplete="off"></div>
            <div class="field"><label for="chat-token">User login token · kept only in this page</label><input class="control" id="chat-token" type="password" autocomplete="off"><button class="btn" id="chat-use-token" type="button">Use API explorer token</button></div>
        </div>
        <div class="guide-actions" style="margin:12px 0">
            <button class="btn btn-primary" id="chat-connect" type="button">Connect</button>
            <button class="btn" id="chat-disconnect" type="button" disabled>Disconnect</button>
            <button class="btn" id="chat-ping" type="button" data-chat-connected disabled>Ping / round trip</button>
        </div>
        <div class="guide-grid">
            <div class="field"><label for="chat-conversation">Conversation UUID</label><input class="control" id="chat-conversation" autocomplete="off"><div class="guide-actions"><button class="btn" id="chat-join" type="button" data-chat-connected disabled>conversation.join</button><button class="btn" id="chat-leave" type="button" data-chat-connected disabled>conversation.leave</button></div></div>
            <div class="field"><label for="chat-body">Message body</label><textarea class="control" id="chat-body" rows="3" placeholder="Send a real test message"></textarea><button class="btn btn-primary" id="chat-send" type="button" data-chat-connected disabled>message.send · saves to database</button></div>
            <div class="flow-note">Listen for <code>message.sent</code> and <code>session.expired</code> below. These are server events. A send ACK confirms persistence, not delivery or reading. On expiry, reconnect with a valid token; reload REST history and rejoin after reconnecting. Avoid resending automatically after an ACK timeout.</div>
        </div>
        <div class="guide-actions" style="margin:12px 0"><button class="btn" id="chat-clear" type="button">Clear log</button><button class="btn" id="chat-copy-example" type="button">Copy app connection example</button></div>
        <pre id="chat-log" aria-label="Socket events and acknowledgements" style="white-space:pre-wrap;overflow-wrap:anywhere;max-height:360px;overflow:auto;background:#101d32;color:#dce8ff;border-radius:8px;padding:14px;font-size:12px">No socket events yet.</pre>
        <p class="hint">Browser origin must be allowed by the chat server. HTTPS pages need an HTTPS socket endpoint. No server shared secret is needed here.</p>
    </div>
</div>
<script src="{{ asset('vendor/socket.io/socket.io.min.js') }}" defer></script>
<script src="{{ asset('js/developer-chat.js') }}" defer></script>
