<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>Developer portal · Nearby Teacher</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#18253a;background:#f3f6fb;font-synthesis:none;--navy:#14243b;--blue:#2864dc;--blue-dark:#1f50b5;--muted:#748196;--line:#e2e8f0;--danger:#bd3440;--green:#18865b;--mono:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}
        *{box-sizing:border-box}body{margin:0;min-height:100vh}button,input,select,textarea{font:inherit}button{cursor:pointer}a{color:inherit}
        .shell{min-height:100vh}.sidebar{position:fixed;inset:0 auto 0 0;width:244px;background:var(--navy);color:#e7edf7;padding:22px 15px;display:flex;flex-direction:column;z-index:3}.brand{display:flex;align-items:center;gap:11px;padding:4px 9px 26px;text-decoration:none}.brand-mark{width:38px;height:38px;border-radius:12px;background:#3c75ed;display:grid;place-items:center;font-weight:800;color:white}.brand strong{display:block;font-size:14px}.brand small{display:block;color:#9eacc2;margin-top:3px;font-size:11px}.nav-label{color:#8998ae;text-transform:uppercase;letter-spacing:.11em;font-weight:700;font-size:10px;padding:4px 12px 9px}.nav{display:grid;gap:6px}.nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:9px;color:#bac7d9;text-decoration:none;font-size:13px;font-weight:600}.nav a:hover,.nav a.active{background:#29436b;color:#fff}.nav-icon{width:20px;text-align:center;font-size:15px}.sidebar-bottom{margin-top:auto;border-top:1px solid #2a3b52;padding:15px 9px 0;color:#9ba9bc;font-size:11px;line-height:1.6}
        .workspace{margin-left:244px;min-height:100vh}.topbar{height:68px;background:#fff;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 clamp(18px,3vw,42px);position:sticky;top:0;z-index:2}.top-title{font-size:13px;color:var(--muted)}.top-title strong{color:#1c293a}.top-actions{display:flex;align-items:center;gap:13px}.secure-pill{padding:6px 9px;border:1px solid #cbe7d8;border-radius:999px;background:#effaf4;color:#197550;font-size:10px;font-weight:750}.top-actions form{margin:0}.logout{border:0;background:transparent;color:#59677c;font-size:12px;font-weight:700;padding:8px;border-radius:8px}.logout:hover{background:#f2f5fa}
        main{max-width:1640px;padding:26px clamp(18px,3vw,42px) 48px;margin:auto}.page-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:20px}.eyebrow{text-transform:uppercase;color:var(--blue);font-size:10px;font-weight:800;letter-spacing:.13em;margin:0 0 7px}.page-heading h1{font-size:clamp(24px,3vw,30px);letter-spacing:-.035em;margin:0;color:#162338}.page-heading p{margin:7px 0 0;color:var(--muted);font-size:13px}.base-origin{max-width:38%;padding:9px 11px;border:1px solid var(--line);background:#fff;border-radius:9px;font-size:11px;color:#536178;overflow-wrap:anywhere}.base-origin strong{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.1em;margin-bottom:4px;color:#8995a7}
        .flash{display:flex;gap:10px;align-items:flex-start;margin:0 0 16px;padding:12px 14px;border:1px solid #bde7d3;border-radius:10px;background:#effaf4;color:#176344;font-size:12px;line-height:1.5}.flash.error{border-color:#f0c5ca;background:#fff3f4;color:#9f2633}.flash strong{display:block;margin-bottom:3px}.flash ul{padding-left:18px;margin:5px 0 0}.flash li+li{margin-top:3px}
        .tab{display:none}.tab.active{display:block}.panel{background:#fff;border:1px solid var(--line);border-radius:13px;box-shadow:0 4px 18px rgba(25,45,75,.035);overflow:hidden}.panel-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:17px 20px;border-bottom:1px solid #edf0f5}.panel-head h2{font-size:14px;margin:0;color:#202e43}.panel-head p{font-size:11px;color:var(--muted);margin:5px 0 0}.panel-body{padding:18px 20px}
        .explorer{display:grid;grid-template-columns:minmax(230px,290px) minmax(0,1fr);gap:16px;align-items:start}.endpoint-panel{position:sticky;top:84px;max-height:calc(100vh - 100px);display:flex;flex-direction:column}.endpoint-tools{padding:13px;border-bottom:1px solid #edf0f5;display:grid;gap:8px}.control,.control select,.control input,.control textarea{width:100%;border:1px solid #d8e0eb;border-radius:8px;background:#fff;color:#1a2638;padding:9px 10px;font:inherit;font-size:12px;outline:none}.control:focus,.control select:focus,.control input:focus,.control textarea:focus{border-color:#6591ed;box-shadow:0 0 0 3px rgba(53,105,217,.11)}.endpoint-list{overflow:auto;padding:8px}.endpoint-item{display:block;width:100%;text-align:left;background:transparent;border:0;padding:9px 9px;border-radius:8px;color:#344258}.endpoint-item:hover,.endpoint-item.selected{background:#eff4ff}.endpoint-category{display:block;padding:11px 8px 5px;color:#8692a4;text-transform:uppercase;letter-spacing:.09em;font-size:9px;font-weight:800}.endpoint-line{display:flex;align-items:center;gap:7px}.method{flex:none;display:inline-grid;place-items:center;min-width:43px;padding:4px 5px;border-radius:5px;background:#eaf0fb;color:#395b99;font-size:9px;font-weight:800}.method.post{background:#eaf8f1;color:#198154}.method.patch,.method.put{background:#fff5e5;color:#926317}.method.delete{background:#fff0f1;color:#ae3640}.endpoint-path{font:10px var(--mono);overflow-wrap:anywhere}.endpoint-meta{display:block;color:#8793a4;font-size:10px;margin:5px 0 0 50px}
        .tool-column{min-width:0;display:grid;gap:14px}.request-panel,.response-panel{min-width:0}.request-meta{display:flex;align-items:center;gap:8px}.access-badge,.category-badge{border-radius:999px;background:#f0f3f8;color:#59677c;padding:5px 8px;font-size:10px;font-weight:700}.request-form{padding:18px 20px}.field{min-width:0;margin-bottom:14px}.field>label{display:flex;align-items:center;gap:6px;color:#354258;font-size:11px;font-weight:750;margin-bottom:6px}.field .hint{color:#8793a4;font-weight:450;font-size:10px}.field input,.field select,.field textarea{width:100%;min-height:39px;border:1px solid #d8e0eb;border-radius:8px;background:#fff;padding:9px 11px;color:#1a2638;font:inherit;font-size:12px;outline:none}.field textarea{min-height:120px;resize:vertical;font-family:var(--mono);font-size:11px;line-height:1.55}.field input:focus,.field select:focus,.field textarea:focus{border-color:#6591ed;box-shadow:0 0 0 3px rgba(53,105,217,.1)}.field input[readonly]{background:#f8f9fc;color:#67758b}.field-grid{display:grid;grid-template-columns:150px minmax(0,1fr);gap:11px}.field-grid .field{margin-bottom:12px}.param-grid,.config-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0 12px}.param-grid:empty{display:none}.request-controls{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding-top:13px;border-top:1px solid #edf0f5}.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:36px;border:1px solid #dce3ed;border-radius:8px;padding:7px 11px;background:#fff;color:#344258;font-size:11px;font-weight:750;text-decoration:none}.btn:hover{background:#f5f7fb}.btn-primary{background:var(--blue);border-color:var(--blue);color:#fff}.btn-primary:hover{background:var(--blue-dark);border-color:var(--blue-dark)}.btn:disabled{opacity:.65;cursor:wait}.request-tip{margin-left:auto;color:#8995a7;font-size:10px}
        .response-toolbar{display:flex;align-items:center;gap:8px}.status-pill{border-radius:999px;background:#edf1f6;color:#66748a;padding:5px 9px;font-size:10px;font-weight:800}.status-pill.success{background:#e8f8ef;color:#16804f}.status-pill.failure{background:#fff0f1;color:#ae3640}.response-time{font-size:10px;color:#8793a4}.response-body{min-height:180px;max-height:55vh;overflow:auto;background:#101a2a;color:#d9e3f3;padding:16px 17px;margin:0;font:11px/1.6 var(--mono);white-space:pre-wrap;overflow-wrap:anywhere}.response-empty{display:grid;place-items:center;text-align:center;color:#8995a7;min-height:180px;padding:20px;font-size:12px}
        .config-warning{border:1px solid #f0d99b;background:#fff9e9;color:#755b1a;border-radius:10px;padding:12px 14px;margin-bottom:15px;font-size:11px;line-height:1.6}.config-warning strong{display:block;margin-bottom:3px}.config-group{margin-bottom:14px}.config-group:last-child{margin-bottom:0}.config-group h3{margin:0;padding:12px 16px;background:#f8f9fc;border-bottom:1px solid #edf0f5;color:#314158;font-size:12px}.config-group .config-grid{padding:15px 16px 2px}.config-field{padding:0 0 13px;min-width:0}.config-field label{display:flex;align-items:center;justify-content:space-between;gap:8px;color:#354258;font-size:10px;font-weight:800;margin-bottom:5px}.config-field code{font:9px var(--mono);color:#8190a4;font-weight:500}.config-field input,.config-field textarea{width:100%;min-height:36px;border:1px solid #d8e0eb;border-radius:7px;background:#fff;padding:8px 9px;color:#1a2638;font-size:11px;outline:none}.config-field textarea{height:63px;resize:vertical}.config-field input:focus,.config-field textarea:focus{border-color:#6591ed;box-shadow:0 0 0 3px rgba(53,105,217,.1)}.config-foot{display:flex;justify-content:space-between;gap:12px;color:#8490a1;font-size:10px;margin-top:5px}.set-state{font-size:9px;font-weight:750;color:#8793a4}.set-state.set{color:#16804f}.clear-setting{display:flex;align-items:center;gap:5px;color:#a13c46;font-size:9px;font-weight:600;margin-top:6px}.clear-setting input{width:13px;height:13px;min-height:auto;accent-color:#c23b48}.confirm-box{display:flex;align-items:flex-start;gap:9px;background:#fff9e9;border:1px solid #f0d99b;border-radius:9px;padding:12px;color:#725c27;font-size:11px;line-height:1.5;margin:16px 0}.confirm-box input{margin-top:2px;accent-color:var(--blue)}.settings-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-top:1px solid #edf0f5}.settings-actions small{color:#8793a4;font-size:10px}
        .config-tools{display:flex;align-items:center;gap:10px;margin-bottom:12px}.config-tools input{max-width:420px}.config-tools span{font-size:10px;color:#8793a4}.config-field .field-error{font-size:10px;color:#b12836;margin:5px 0 0}
        .nav{overflow:auto;align-content:start;flex:1}.nav-category{font-size:11px!important;padding:7px 12px!important}.nav-category .nav-icon{font-size:10px;color:#8394ad}.nav-section{color:#8998ae;text-transform:uppercase;letter-spacing:.11em;font-weight:700;font-size:9px;padding:15px 12px 5px}.nav-count{margin-left:auto;color:#8293ac;font-size:9px}
        .stats-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:15px}.stat-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:16px}.stat-card strong{display:block;font-size:23px;color:#18335e;letter-spacing:-.04em}.stat-card span{display:block;margin-top:5px;color:#78869a;font-size:11px}.overview-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(250px,.65fr);gap:15px}.workflow-list{display:grid;gap:10px}.workflow-card{display:grid;grid-template-columns:34px minmax(0,1fr) auto;align-items:center;gap:12px;padding:13px;border:1px solid #e7ebf2;border-radius:10px}.step-number{width:30px;height:30px;border-radius:50%;background:#eaf0ff;color:#2859bd;display:grid;place-items:center;font-size:12px;font-weight:800}.workflow-card strong{display:block;font-size:12px;color:#26364d}.workflow-card p{margin:4px 0 0;color:#7b8798;font-size:10px;line-height:1.5}.flow-note{padding:12px;border-radius:9px;background:#f5f8ff;color:#526580;font-size:11px;line-height:1.6}.guide-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.guide-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:17px}.guide-card h3{margin:0 0 7px;font-size:12px;color:#26364d}.guide-card p,.guide-card li{font-size:11px;line-height:1.6;color:#758297}.guide-card ul{padding-left:17px}.guide-card code{font:10px var(--mono);color:#344a6c;overflow-wrap:anywhere}.guide-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:15px}.endpoint-guide{padding:9px 11px;margin:0 0 13px;border:1px solid #dce7fc;border-radius:8px;background:#f5f8ff;color:#526580;font-size:10px;line-height:1.5}
        @media(max-width:1000px){.explorer{grid-template-columns:230px minmax(0,1fr)}.sidebar{width:215px}.workspace{margin-left:215px}.field-grid{grid-template-columns:120px minmax(0,1fr)}}
        @media(max-width:720px){.sidebar{position:static;width:auto;padding:9px 12px}.brand{padding:2px 5px 8px}.brand-mark{width:32px;height:32px}.nav-label,.sidebar-bottom{display:none}.nav{display:flex;overflow:auto}.nav a{flex:none;padding:9px 10px;font-size:11px}.nav-icon{display:none}.workspace{margin:0}.topbar{height:56px;padding:0 14px}.secure-pill{display:none}main{padding:20px 13px 34px}.page-heading{align-items:flex-start}.page-heading h1{font-size:25px}.base-origin{max-width:45%;font-size:9px}.explorer{grid-template-columns:1fr}.endpoint-panel{position:static;max-height:340px}.endpoint-list{max-height:245px}.endpoint-tools{grid-template-columns:1fr 1fr}.config-grid,.param-grid{grid-template-columns:1fr}.field-grid{grid-template-columns:105px minmax(0,1fr)}.request-form,.panel-body{padding:15px}.panel-head{padding:15px}.response-body{max-height:50vh}.request-tip{width:100%;margin:0}.settings-actions{align-items:flex-start;flex-direction:column}}
        @media(max-width:720px){.overview-grid,.guide-grid{grid-template-columns:1fr}.stats-grid{gap:6px}.stat-card{padding:11px 8px}.workflow-card{grid-template-columns:30px minmax(0,1fr)}.workflow-card>.btn{grid-column:2;justify-self:start}.nav-category,.nav-section{display:none!important}}
    </style>
</head>
<body>
<div class="shell">
    <aside class="sidebar" aria-label="Developer portal navigation">
        <a class="brand" href="{{ route('developer.portal') }}"><span class="brand-mark" aria-hidden="true">N</span><span><strong>Nearby Teacher</strong><small>Developer workspace</small></span></a>
        <div class="nav-label">Developer tools</div>
        <nav class="nav">
            <a href="#overview" class="active" data-tab="overview"><span class="nav-icon" aria-hidden="true">⌂</span>Overview</a>
            <a href="#authentication-flow" data-tab="authentication-flow"><span class="nav-icon" aria-hidden="true">↗</span>Google → OTP flow</a>
            <a href="#api-explorer" data-tab="api-explorer"><span class="nav-icon" aria-hidden="true">⌘</span>API explorer</a>
            <div class="nav-section">API categories</div>
            @foreach ($categories as $category)
                <a href="#api-explorer" class="nav-category" data-tab="api-explorer" data-category="{{ $category }}"><span class="nav-icon" aria-hidden="true">•</span>{{ $category }} <span class="nav-count">{{ collect($catalog)->where('category', $category)->count() }}</span></a>
            @endforeach
            <div class="nav-section">Project</div>
            <a href="#realtime" data-tab="realtime"><span class="nav-icon" aria-hidden="true">◉</span>WebSockets</a>
            <a href="#configuration" data-tab="configuration"><span class="nav-icon" aria-hidden="true">⚙</span>Configuration</a>
        </nav>
        <div class="sidebar-bottom">Restricted developer access<br>Secrets are never returned to this page.</div>
    </aside>
    <div class="workspace">
        <header class="topbar">
            <div class="top-title"><strong>Developer portal</strong><span> / API workbench</span></div>
            <div class="top-actions"><span class="secure-pill">● Protected session</span><form method="post" action="{{ route('developer.logout') }}">@csrf<button class="logout" type="submit">Sign out</button></form></div>
        </header>
        <main>
            @if (session('status'))
                <div class="flash" role="status"><span aria-hidden="true">✓</span><div><strong>Saved</strong>{{ session('status') }}</div></div>
            @endif
            @if ($errors->any())
                <div class="flash error" role="alert"><span aria-hidden="true">!</span><div><strong>Please review the issue below</strong><ul>@foreach ($errors->all() as $message)<li>{{ $message }}</li>@endforeach</ul></div></div>
            @endif
            <div class="page-heading">
                <div><p class="eyebrow">Project tools</p><h1>API workbench</h1><p>Browse registered endpoints, configure a request, and inspect the live response. Mutating requests affect real application data.</p></div>
                <div class="base-origin"><strong>Request origin · same-origin only</strong><span id="current-origin">{{ $baseUrl }}</span></div>
            </div>

            <section class="tab active" id="overview" aria-label="Developer overview">
                <div class="stats-grid">
                    <div class="stat-card"><strong>{{ count($catalog) }}</strong><span>registered API operations</span></div>
                    <div class="stat-card"><strong>{{ count($categories) }}</strong><span>API categories</span></div>
                    <div class="stat-card"><strong>3 steps</strong><span>Google sign-in → phone OTP → link phone</span></div>
                </div>
                <div class="overview-grid">
                    <section class="panel">
                        <div class="panel-head"><div><h2>Authentication workflow</h2><p>Run the requests in order; OTP delivery and verification remain interactive.</p></div><button class="btn btn-primary" type="button" data-tab="authentication-flow">Open workflow</button></div>
                        <div class="panel-body workflow-list">
                            <div class="workflow-card"><span class="step-number">1</span><div><strong>Verify Google sign-in</strong><p>Exchange a Firebase Google ID token for the app's bearer token.</p></div><button class="btn" type="button" data-pick-api="POST /auth/google">Try step</button></div>
                            <div class="workflow-card"><span class="step-number">2</span><div><strong>Request phone OTP</strong><p>Send a code to the user's international phone number.</p></div><button class="btn" type="button" data-pick-api="POST /auth/otp/request">Try step</button></div>
                            <div class="workflow-card"><span class="step-number">3</span><div><strong>Link verified phone</strong><p>Use the Google response token and the delivered six-digit code.</p></div><button class="btn" type="button" data-pick-api="PATCH /auth/link-phone">Try step</button></div>
                        </div>
                    </section>
                    <section class="panel">
                        <div class="panel-head"><div><h2>Workbench shortcuts</h2><p>Explore and configure this project.</p></div></div>
                        <div class="panel-body workflow-list">
                            <div class="flow-note"><strong>API explorer</strong><br>Filter registered routes by category, method, path, or access level. Request examples are prefilled from the current API validation rules.</div>
                            <div class="flow-note"><strong>WebSockets</strong><br>Review Socket.IO configuration, authenticated rooms and live message events.</div>
                            <div class="flow-note"><strong>Configuration</strong><br>Manage allowlisted project settings. Secret values are not exposed.</div>
                            <div class="guide-actions"><button class="btn" type="button" data-tab="api-explorer">Browse APIs</button><button class="btn" type="button" data-tab="realtime">WebSocket setup</button><button class="btn" type="button" data-tab="configuration">Project settings</button></div>
                        </div>
                    </section>
                </div>
            </section>

            <section class="tab" id="authentication-flow" aria-label="Authentication workflow">
                <div class="panel">
                    <div class="panel-head"><div><h2>Google sign-in → phone verification</h2><p>Complete each step in order; use the response-token shortcut after step 1.</p></div><span class="category-badge">Authentication</span></div>
                    <div class="panel-body workflow-list">
                        <div class="workflow-card"><span class="step-number">1</span><div><strong>POST /auth/google</strong><p>Send a verified Firebase Google ID token. The response provides the Nearby Teacher API token.</p></div><button class="btn btn-primary" type="button" data-pick-api="POST /auth/google">Configure request</button></div>
                        <div class="workflow-card"><span class="step-number">2</span><div><strong>POST /auth/otp/request</strong><p>Send the user's phone in international format. Use the response otp in local test mode, or the delivered SMS code.</p></div><button class="btn" type="button" data-pick-api="POST /auth/otp/request">Configure request</button></div>
                        <div class="workflow-card"><span class="step-number">3</span><div><strong>PATCH /auth/link-phone</strong><p>Requires the Google bearer token and the phone/code pair. This links the phone to the signed-in account.</p></div><button class="btn" type="button" data-pick-api="PATCH /auth/link-phone">Configure request</button></div>
                        <div class="flow-note"><strong>Registration → email login</strong><p>On the registration page: request OTP → verify OTP → copy the response token → submit the full form with Register. Autosave is disabled until registration succeeds. Returning users use email/password only.</p><div class="guide-actions"><button class="btn" type="button" data-pick-api="POST /auth/register">Register</button><button class="btn" type="button" data-pick-api="POST /auth/login">Email login</button><button class="btn" type="button" data-pick-api="PATCH /auth/onboarding">Profile autosave</button></div></div>
                        <div class="flow-note"><strong>Forgot password and email verification</strong><p>Request a reset code by email, then submit it with matching new passwords. Codes expire after 10 minutes and are never included in the API response. Keep the notifications worker running. After reset, log in again.</p><div class="guide-actions"><button class="btn" type="button" data-pick-api="POST /auth/forgot-password">Forgot password</button><button class="btn" type="button" data-pick-api="POST /auth/reset-password">Reset password</button><button class="btn" type="button" data-pick-api="POST /auth/email/verification/request">Request email verification</button><button class="btn" type="button" data-pick-api="POST /auth/email/verification/verify">Verify email</button></div></div>
                        <div class="flow-note"><strong>Alternative:</strong> phone OTP is for registration verification. Returning users log in with email/password. Request and verify the code, then submit the full registration form with the response token. Do not use OTP verify to link a phone to a Google account.<div class="guide-actions"><button class="btn" type="button" data-pick-api="POST /auth/otp/request">1 · Request OTP</button><button class="btn" type="button" data-pick-api="POST /auth/otp/verify">2 · Verify OTP</button></div></div>
                    </div>
                </div>
            </section>

            <section class="tab" id="realtime" aria-label="WebSocket setup">
                @include('developer.chat-tester')
                <div class="panel">
                    <div class="panel-head"><div><h2>Chat and WebSockets</h2><p>Use Socket.IO 4 for live chat; Laravel validates and saves messages.</p></div><span class="category-badge">Chat and realtime</span></div>
                    <div class="panel-body guide-grid">
                        <article class="guide-card"><h3>1 · Configure Socket.IO</h3><p>Set the Socket.IO server URLs, shared secret and allowed origins in project configuration. Keep the secret server-side.</p><ul><li>Run <code>npm run chat:start</code>.</li><li>Run <code>php artisan queue:work --queue=broadcasts --sleep=1 --tries=3 --timeout=30</code>.</li><li>Restart Node and queue workers after changing settings.</li></ul><button class="btn" type="button" data-tab="configuration">Open configuration</button></article>
                        <article class="guide-card"><h3>2 · Authenticate private channels</h3><p>Connect using auth.token with the Laravel bearer. Use conversation.join with conversationId; the Socket.IO server checks membership through Laravel.</p><p><code>private-conversation.{conversationId}</code></p><button class="btn" type="button" data-pick-api="POST /chat/socket/authorize">Test room auth</button></article>
                        <article class="guide-card"><h3>3 · Send and receive messages</h3><p>Start or open a conversation with the chat endpoints. Use message.send or the REST send API; message.sent delivers saved messages to participants. Recover history using cursor pagination.</p><div class="guide-actions"><button class="btn" type="button" data-pick-api="POST /messages/start">Start conversation</button><button class="btn" type="button" data-pick-api="GET /messages">List conversations</button></div></article>
                    </div>
                </div>
            </section>

            <section class="tab" id="api-explorer" aria-label="API explorer">
                <div class="explorer">
                    <section class="panel endpoint-panel" aria-label="Registered API endpoints">
                        <div class="panel-head"><div><h2>API catalog</h2><p>{{ count($catalog) }} registered routes</p></div></div>
                        <div class="endpoint-tools">
                            <input class="control" id="endpoint-search" type="search" placeholder="Search paths, methods…" aria-label="Search API endpoints">
                            <select class="control" id="category-filter" aria-label="Filter by category"><option value="">All categories</option></select>
                        </div>
                        <div class="endpoint-list" id="endpoint-list"></div>
                    </section>

                    <div class="tool-column">
                        <section class="panel request-panel" aria-labelledby="request-heading">
                            <div class="panel-head"><div><h2 id="request-heading">Request builder</h2><p id="request-description">Choose an endpoint or enter a same-origin path.</p></div><div class="request-meta"><span class="category-badge" id="selected-category">Custom</span><span class="access-badge" id="selected-access">API request</span></div></div>
                            <form class="request-form" id="api-form">
                                <div class="field-grid">
                                    <div class="field"><label for="request-method">Method</label><select id="request-method" class="control"><option>GET</option><option>POST</option><option>PUT</option><option>PATCH</option><option>DELETE</option></select></div>
                                    <div class="field"><label for="request-path">Path <span class="hint">relative path on this application</span></label><input id="request-path" class="control" type="text" value="/subjects" autocomplete="off" spellcheck="false" required></div>
                                </div>
                                <div class="field" id="path-params-wrap" hidden><label>Path parameters</label><div class="param-grid" id="path-params"></div></div>
                                <div class="endpoint-guide" id="endpoint-guide" hidden></div>
                                <div class="field"><label for="request-query">Query string <span class="hint">optional, e.g. lat=28.6&amp;lng=77.2</span></label><input id="request-query" class="control" type="text" placeholder="key=value&amp;another=value" autocomplete="off"></div>
                                <div class="field"><label for="bearer-token">Bearer token <span class="hint">kept only in this page; never saved</span></label><input id="bearer-token" class="control" type="password" autocomplete="off" placeholder="Paste a user or admin API token"></div>
                                <div class="field"><label for="request-headers">Additional headers <span class="hint">JSON object</span></label><textarea id="request-headers" spellcheck="false">{"Accept":"application/json","Content-Type":"application/json"}</textarea></div>
                                <div class="field" id="request-body-field"><label for="request-body">JSON body <span class="hint">omit for GET/DELETE with no body</span></label><textarea id="request-body" spellcheck="false">{}</textarea></div>
                                <div class="request-controls"><button class="btn btn-primary" id="send-request" type="submit">▶ Send request</button><button class="btn" id="clear-response" type="button">Clear response</button><span class="request-tip">Requests are sent to this application only.</span></div>
                            </form>
                        </section>
                        <section class="panel response-panel" aria-labelledby="response-heading">
                            <div class="panel-head"><div><h2 id="response-heading">Response</h2><p id="response-content-type">Run a request to inspect its status, timing, and body.</p></div><div class="response-toolbar"><span id="response-status" class="status-pill">Ready</span><span id="response-time" class="response-time"></span><button class="btn" id="copy-response" type="button">Copy</button></div></div>
                            <div class="panel-body" id="token-actions" style="padding:8px 16px" hidden><button class="btn" id="use-response-token" type="button">Use response token as bearer</button></div>
                            <pre class="response-body" id="response-body" hidden></pre>
                            <div class="response-empty" id="response-empty">Select an API endpoint, provide any required credentials, then send your request.</div>
                        </section>
                    </div>
                </div>
            </section>

            <section class="tab" id="configuration" aria-label="Project configuration">
                <div class="config-warning" role="note"><strong>High-impact project settings</strong>
                    Updates write to the application <code>.env</code>, then clear Laravel's configuration cache. Incorrect database, encryption, or integration settings can interrupt the application. Existing secret values are never shown; leave a secret blank to keep it, or explicitly choose Clear.
                    Changing <code>APP_KEY</code> invalidates encrypted cookies and sessions. Restart queue workers, Socket.IO, Octane, and other long-running processes after saving.
                </div>
                <div class="config-tools"><input class="control" id="settings-search" type="search" placeholder="Filter configuration keys…" aria-label="Filter configuration settings"><span id="settings-count"></span></div>
                <form method="post" action="{{ route('developer.configuration.update') }}">
                    @csrf
                    <div class="panel">
                        @foreach ($groups as $group => $groupSettings)
                            <section class="config-group" aria-labelledby="group-{{ \Illuminate\Support\Str::slug($group) }}">
                                <h3 id="group-{{ \Illuminate\Support\Str::slug($group) }}">{{ $group }}</h3>
                                <div class="config-grid">
                                    @foreach ($settings[$group] ?? [] as $setting)
                                        @php
                                            $key = $setting['key'];
                                            $isSecret = in_array($setting['type'], ['secret', 'secret_json'], true);
                                            $inputId = 'setting-' . strtolower($key);
                                        @endphp
                                        <div class="config-field">
                                            <label for="{{ $inputId }}"><span>{{ $setting['label'] }}</span><code>{{ $key }}</code></label>
                                            @if ($setting['type'] === 'secret_json')
                                                <textarea id="{{ $inputId }}" name="settings[{{ $key }}]" placeholder="{{ $setting['configured'] ? 'Secret is saved. Leave blank to keep it.' : 'Not configured. Paste JSON to set it.' }}" autocomplete="new-password" spellcheck="false"></textarea>
                                            @else
                                                <input id="{{ $inputId }}" name="settings[{{ $key }}]" type="{{ $isSecret ? 'password' : ($setting['type'] === 'boolean' ? 'text' : $setting['type']) }}" value="{{ $setting['value'] }}" placeholder="{{ $isSecret ? ($setting['configured'] ? 'Saved; blank keeps current value' : 'Not configured') : 'Leave blank to keep current value' }}" autocomplete="{{ $isSecret ? 'new-password' : 'off' }}" @if ($setting['type'] === 'boolean') list="boolean-options" @endif>
                                            @endif
                                            @error('settings.'.$key)<p class="field-error">{{ $message }}</p>@enderror
                                            <div class="config-foot"><span>{{ $isSecret ? ($setting['configured'] ? 'Secret is set · value hidden' : 'No value set') : 'Blank keeps the current setting' }}</span><span @class(['set-state', 'set' => $setting['configured']])>{{ $setting['configured'] ? 'SET' : 'EMPTY' }}</span></div>
                                            @if ($key !== 'APP_KEY')
                                                <label class="clear-setting"><input type="checkbox" name="clear[]" value="{{ $key }}">Clear this setting</label>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </section>
                        @endforeach
                    </div>
                    <datalist id="boolean-options"><option value="true"><option value="false"></datalist>
                    <label class="confirm-box"><input type="checkbox" name="confirm_changes" value="1" required><span>I understand configuration changes can break app connectivity, invalidate sessions, or require service restarts. Save only settings I intend to change.</span></label>
                    @error('confirm_changes')<p class="field-error">{{ $message }}</p>@enderror
                    <div class="panel settings-actions"><small>Developer login credentials are managed separately with <code>php artisan developer:setup-login</code>.</small><button class="btn btn-primary" type="submit">Save project configuration</button></div>
                </form>
            </section>
        </main>
    </div>
</div>
<script>
(() => {
    document.getElementById('current-origin').textContent = window.location.origin;
    const apis = @js($catalog);
    const list = document.getElementById('endpoint-list');
    const categoryFilter = document.getElementById('category-filter');
    const search = document.getElementById('endpoint-search');
    const method = document.getElementById('request-method');
    const path = document.getElementById('request-path');
    const query = document.getElementById('request-query');
    const token = document.getElementById('bearer-token');
    const tokenLabel = document.querySelector('label[for="bearer-token"]');
    if (tokenLabel?.firstChild) tokenLabel.firstChild.textContent = 'API access token ';
    token.setAttribute('aria-label', 'API access token');
    const headersInput = document.getElementById('request-headers');
    const body = document.getElementById('request-body');
    const bodyField = document.getElementById('request-body-field');
    const paramWrap = document.getElementById('path-params-wrap');
    const params = document.getElementById('path-params');
    const selectedCategory = document.getElementById('selected-category');
    const selectedAccess = document.getElementById('selected-access');
    const description = document.getElementById('request-description');
    const sendButton = document.getElementById('send-request');
    const responseStatus = document.getElementById('response-status');
    const responseTime = document.getElementById('response-time');
    const responseBody = document.getElementById('response-body');
    const responseEmpty = document.getElementById('response-empty');
    const responseType = document.getElementById('response-content-type');
    const useResponseToken = document.getElementById('use-response-token');
    const tokenActions = document.getElementById('token-actions');
    const settingsSearch = document.getElementById('settings-search');
    const settingsCount = document.getElementById('settings-count');
    let currentId = null;

    const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
    const categories = [...new Set(apis.map((api) => api.category))];
    categories.forEach((category) => {
        const option = document.createElement('option');
        option.value = category;
        option.textContent = category;
        categoryFilter.append(option);
    });

    function renderCatalog() {
        const term = search.value.trim().toLowerCase();
        const category = categoryFilter.value;
        const visible = apis.filter((api) => (!category || api.category === category)
            && (!term || `${api.method} ${api.path} ${api.category} ${api.access}`.toLowerCase().includes(term)));
        let currentCategory = null;
        list.replaceChildren();
        visible.forEach((api) => {
            if (api.category !== currentCategory) {
                currentCategory = api.category;
                const heading = document.createElement('div');
                heading.className = 'endpoint-category';
                heading.textContent = currentCategory;
                list.append(heading);
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.className = `endpoint-item${api.id === currentId ? ' selected' : ''}`;
            button.dataset.endpoint = api.id;
            button.innerHTML = `<span class="endpoint-line"><span class="method ${api.method.toLowerCase()}">${escapeHtml(api.method)}</span><span class="endpoint-path">${escapeHtml(api.path)}</span></span><span class="endpoint-meta">${escapeHtml(api.access)}</span>`;
            list.append(button);
        });
        if (visible.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'response-empty';
            empty.textContent = 'No API endpoints match this filter.';
            list.append(empty);
        }
    }

    function updateParams() {
        const names = [...path.value.matchAll(/\{([^}]+)\}/g)].map((match) => match[1]);
        params.replaceChildren();
        paramWrap.hidden = names.length === 0;
        names.forEach((name) => {
            const field = document.createElement('div');
            field.className = 'field';
            const label = document.createElement('label');
            const inputId = `path-param-${name}`;
            label.htmlFor = inputId;
            label.textContent = name;
            const input = document.createElement('input');
            input.id = inputId;
            input.dataset.param = name;
            input.placeholder = `Enter ${name}`;
            input.value = currentApi?.pathDefaults?.[name] ?? '';
            input.autocomplete = 'off';
            input.className = 'control';
            field.append(label, input);
            params.append(field);
        });
    }

    let currentApi = null;
    const endpointGuide = document.getElementById('endpoint-guide');

    function selectApi(api) {
        currentId = api.id;
        currentApi = api;
        method.value = api.method;
        path.value = api.path;
        query.value = api.queryExample || '';
        body.value = JSON.stringify(api.bodyExample ?? {}, null, 2);
        selectedCategory.textContent = api.category;
        selectedAccess.textContent = api.access;
        description.textContent = `${api.action} · registered API route`;
        endpointGuide.textContent = api.guide || '';
        endpointGuide.hidden = !api.guide;
        bodyField.hidden = ['GET', 'DELETE'].includes(api.method);
        updateParams();
        renderCatalog();
    }

    list.addEventListener('click', (event) => {
        const button = event.target.closest('[data-endpoint]');
        if (!button) return;
        const api = apis.find((item) => item.id === button.dataset.endpoint);
        if (api) selectApi(api);
    });
    search.addEventListener('input', renderCatalog);
    categoryFilter.addEventListener('change', renderCatalog);
    document.querySelectorAll('[data-category]').forEach((link) => {
        link.addEventListener('click', () => {
            categoryFilter.value = link.dataset.category;
            renderCatalog();
        });
    });
    document.querySelectorAll('[data-pick-api]').forEach((button) => {
        button.addEventListener('click', () => {
            const api = apis.find((item) => item.id === button.dataset.pickApi);
            if (!api) return;
            document.querySelector('[data-tab="api-explorer"]').click();
            categoryFilter.value = api.category;
            selectApi(api);
        });
    });
    path.addEventListener('input', updateParams);
    method.addEventListener('change', () => { bodyField.hidden = ['GET', 'DELETE'].includes(method.value); });
    renderCatalog();

    document.getElementById('api-form').addEventListener('submit', async (event) => {
        event.preventDefault();
        const requestPath = path.value.trim();
        if (!requestPath.startsWith('/') || requestPath.startsWith('//') || requestPath.includes('\\')) {
            showResponse('Path must begin with one slash and remain on this application.', 'Request not sent', null, '');
            return;
        }

        let resolvedPath = requestPath.replace(/\{([^}]+)\}/g, (_placeholder, name) => {
            const value = params.querySelector(`[data-param="${CSS.escape(name)}"]`)?.value.trim() ?? '';
            return value === '' ? `{${name}}` : encodeURIComponent(value);
        });
        if (/\{[^}]+\}/.test(resolvedPath)) {
            showResponse('Fill every required path parameter before sending the request.', 'Missing parameter', null, '');
            return;
        }

        let url;
        try {
            url = new URL(resolvedPath, window.location.origin);
            if (url.origin !== window.location.origin) throw new Error('Requests must stay on this application origin.');
            const queryString = query.value.trim().replace(/^\?/, '');
            if (queryString) new URLSearchParams(queryString).forEach((value, key) => url.searchParams.append(key, value));
        } catch (error) {
            showResponse(error.message || 'The request URL is invalid.', 'Invalid request', null, '');
            return;
        }

        let extraHeaders;
        try {
            extraHeaders = JSON.parse(headersInput.value || '{}');
            if (!extraHeaders || Array.isArray(extraHeaders) || typeof extraHeaders !== 'object') throw new Error();
        } catch {
            showResponse('Additional headers must be a valid JSON object.', 'Invalid headers', null, '');
            return;
        }

        const requestHeaders = new Headers();
        for (const [name, value] of Object.entries(extraHeaders)) {
            if (typeof value !== 'string' && typeof value !== 'number') {
                showResponse(`Header "${name}" must have a text or numeric value.`, 'Invalid headers', null, '');
                return;
            }
            try {
                requestHeaders.set(name, String(value));
            } catch {
                showResponse(`Header "${name}" is not valid in a browser request.`, 'Invalid headers', null, '');
                return;
            }
        }
        if (!requestHeaders.has('Accept')) requestHeaders.set('Accept', 'application/json');
        if (!['GET', 'DELETE'].includes(method.value) && !requestHeaders.has('Content-Type')) {
            requestHeaders.set('Content-Type', 'application/json');
        }
        if (token.value.trim()) requestHeaders.set('Authorization', `Bearer ${token.value.trim()}`);

        let requestBody;
        if (!['GET', 'DELETE'].includes(method.value) && body.value.trim()) {
            try {
                JSON.parse(body.value);
                requestBody = body.value;
            } catch {
                showResponse('The request body must be valid JSON.', 'Invalid JSON body', null, '');
                return;
            }
        }

        sendButton.disabled = true;
        sendButton.textContent = 'Sending…';
        const started = performance.now();
        const abortController = new AbortController();
        const timeout = setTimeout(() => abortController.abort(), 45000);
        try {
            const response = await fetch(url.pathname + url.search, {
                method: method.value,
                headers: requestHeaders,
                body: requestBody,
                credentials: 'same-origin',
                redirect: 'error',
                signal: abortController.signal,
            });
            const raw = await response.text();
            const contentType = response.headers.get('content-type') || 'unknown';
            let display = raw;
            try {
                display = JSON.stringify(JSON.parse(raw), null, 2);
            } catch {
                if (raw === '') display = '(empty response body)';
            }
            showResponse(display, `${response.status} ${response.statusText || ''}`.trim(), `${Math.round(performance.now() - started)} ms`, contentType);
        } catch (error) {
            const message = error.name === 'AbortError'
                ? 'Request timed out after 45 seconds.'
                : `Request failed: ${error.message}. Confirm the application is reachable from this browser.`;
            showResponse(message, 'Network error', `${Math.round(performance.now() - started)} ms`, '');
        } finally {
            clearTimeout(timeout);
            sendButton.disabled = false;
            sendButton.textContent = '▶ Send request';
        }
    });

    function showResponse(content, status, time, contentType) {
        responseEmpty.hidden = true;
        responseBody.hidden = false;
        responseBody.textContent = content;
        responseStatus.textContent = status;
        responseStatus.className = `status-pill ${/^[23]\d\d/.test(status) ? 'success' : (/^\d{3}/.test(status) ? 'failure' : '')}`;
        responseTime.textContent = time || '';
        responseType.textContent = contentType ? `Content-Type: ${contentType}` : 'Request details';
        let responseToken = null;
        try {
            responseToken = JSON.parse(content)?.token;
        } catch {
            responseToken = null;
        }
        tokenActions.hidden = !/^2\d\d/.test(status) || typeof responseToken !== 'string' || responseToken.length === 0;
    }

    useResponseToken.addEventListener('click', () => {
        try {
            const responseToken = JSON.parse(responseBody.textContent)?.token;
            if (typeof responseToken !== 'string' || responseToken.length === 0) {
                responseType.textContent = 'The response does not contain a usable API token.';
                tokenActions.hidden = true;
                return;
            }
            token.value = responseToken;
            responseType.textContent = 'Response token copied into the bearer-token field for the next request.';
        } catch {
            responseType.textContent = 'The response is not valid JSON.';
            tokenActions.hidden = true;
        }
    });

    document.getElementById('clear-response').addEventListener('click', () => {
        responseBody.hidden = true;
        responseEmpty.hidden = false;
        responseStatus.textContent = 'Ready';
        responseStatus.className = 'status-pill';
        responseTime.textContent = '';
        responseType.textContent = 'Run a request to inspect its status, timing, and body.';
        tokenActions.hidden = true;
    });
    document.getElementById('copy-response').addEventListener('click', async () => {
        if (responseBody.hidden) return;
        try {
            await navigator.clipboard.writeText(responseBody.textContent);
            responseType.textContent = 'Response copied to clipboard.';
        } catch {
            responseType.textContent = 'Clipboard access is unavailable in this browser context.';
        }
    });

    document.querySelectorAll('[data-tab]').forEach((link) => {
        link.addEventListener('click', (event) => {
            event.preventDefault();
            const target = link.dataset.tab;
            document.querySelectorAll('.tab').forEach((tab) => tab.classList.toggle('active', tab.id === target));
            document.querySelectorAll('[data-tab]').forEach((item) => item.classList.toggle('active', item === link));
            if (target === 'api-explorer') renderCatalog();
            window.history.replaceState(null, '', `#${target}`);
        });
    });
    const initialTab = window.location.hash.slice(1)
        || new URLSearchParams(window.location.search).get('tab')
        || 'overview';
    const initialTabLink = document.querySelector(`[data-tab="${CSS.escape(initialTab)}"]`);
    if (initialTabLink) {
        initialTabLink.click();
    }

    function filterSettings() {
        const term = settingsSearch.value.trim().toLowerCase();
        let visible = 0;
        document.querySelectorAll('.config-group').forEach((group) => {
            const fields = [...group.querySelectorAll('.config-field')];
            fields.forEach((field) => {
                const matches = !term || field.textContent.toLowerCase().includes(term);
                field.hidden = !matches;
                if (matches) visible += 1;
            });
            group.hidden = fields.every((field) => field.hidden);
        });
        settingsCount.textContent = `${visible} setting${visible === 1 ? '' : 's'}`;
    }
    settingsSearch.addEventListener('input', filterSettings);
    filterSettings();
})();
</script>
</body>
</html>
