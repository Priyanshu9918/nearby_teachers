@inject('adminNavigation', 'App\Support\AdminNavigation')
@php($navigationGroups = $adminNavigation->groups())
<aside class="sidebar" id="admin-sidebar" aria-label="Admin sidebar">
    <div class="sidebar-brand-row">
        <a class="brand" href="{{ route('admin.dashboard') }}">
            <span class="brand-mark"><x-admin-icon name="book" /></span>
            <span><strong>Nearby Teacher</strong><small>BSL ADMIN WORKSPACE</small></span>
        </a>
        <button type="button" class="icon-button mobile-only" id="close-admin-nav" aria-label="Close navigation"><x-admin-icon name="close" /></button>
    </div>

    <div class="nav-search"><x-admin-icon name="search" /><input id="admin-nav-search" type="search" placeholder="Find a page…" aria-label="Find an admin page" autocomplete="off"></div>
    <form class="sidebar-user-search" method="get" action="{{ route('admin.reports.index', ['report' => 'users']) }}" role="search">
        <label for="admin-quick-user-search">Find a user</label>
        <div><input id="admin-quick-user-search" type="search" name="q" value="{{ request()->route('report') === 'users' ? request('q') : '' }}" placeholder="Name, phone or ID"><button type="submit" aria-label="Search users"><x-admin-icon name="search" /></button></div>
    </form>

    <nav class="side-nav" aria-label="Admin sections">
        <div class="nav-section-label">Start here</div>
        <a href="{{ route('admin.dashboard') }}" @class(['nav-link', 'active' => request()->routeIs('admin.dashboard')]) @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif data-nav-item><x-admin-icon name="dashboard" /><span>Overview</span></a>
        <a href="{{ route('admin.create', ['section' => 'teachers']) }}" @class(['nav-link', 'active' => request()->routeIs('admin.create') && request()->route('section') === 'teachers']) data-nav-item><x-admin-icon name="plus" /><span>Add a teacher</span></a>

        <div class="nav-section-label">Manage accounts &amp; catalog</div>
        @foreach (['students' => ['Students', 'user'], 'teachers' => ['Teachers', 'users'], 'institutes' => ['Institutes', 'building'], 'subjects' => ['Subjects', 'book']] as $key => [$label, $icon])
            @php($active = (request()->route('section') ?? ($section ?? null)) === $key)
            <a href="{{ route('admin.index', ['section' => $key]) }}" @class(['nav-link', 'active' => $active]) @if($active) aria-current="page" @endif data-nav-item><x-admin-icon :name="$icon" /><span>{{ $label }}</span></a>
        @endforeach

        <div class="nav-section-label">Review &amp; protect</div>
        <a href="{{ route('admin.moderation.photos') }}" @class(['nav-link', 'active' => request()->routeIs('admin.moderation.photos')]) data-nav-item><x-admin-icon name="photo" /><span>Photos to approve</span></a>
        <a href="{{ route('admin.moderation.bsl') }}" @class(['nav-link', 'active' => request()->routeIs('admin.moderation.bsl')]) data-nav-item><x-admin-icon name="check" /><span>BSL Verified centres</span></a>
        <a href="{{ route('admin.moderation.suspended') }}" @class(['nav-link', 'active' => request()->routeIs('admin.moderation.suspended')]) data-nav-item><x-admin-icon name="shield" /><span>Suspended accounts</span></a>
        <a href="{{ route('admin.moderation.duplicates') }}" @class(['nav-link', 'active' => request()->routeIs('admin.moderation.duplicates')]) data-nav-item><x-admin-icon name="users" /><span>Possible duplicates</span></a>
        <a href="{{ route('admin.reports.index', ['report' => 'reports']) }}" @class(['nav-link', 'active' => request()->route('report') === 'reports']) data-nav-item><x-admin-icon name="flag" /><span>Safety reports</span></a>
        <a href="{{ route('admin.reports.index', ['report' => 'support']) }}" @class(['nav-link', 'active' => request()->route('report') === 'support']) data-nav-item><x-admin-icon name="chat" /><span>Support tickets</span></a>
        <a href="{{ route('admin.support.contact') }}" @class(['nav-link', 'active' => request()->routeIs('admin.support.contact*')]) data-nav-item><x-admin-icon name="chat" /><span>Contact BSL links</span></a>

        <div class="nav-section-label">Owner reports</div>
        @foreach ($navigationGroups as $label => $group)
            @php($groupActive = collect($group['items'])->contains('active', true))
            <details class="nav-group" @if($groupActive) open @endif data-nav-group>
                <summary @class(['nav-group-title', 'group-active' => $groupActive])><x-admin-icon :name="$group['icon']" /><span>{{ $label }}</span><x-admin-icon class="nav-chevron" name="chevron" /></summary>
                <div class="nav-group-items">@foreach ($group['items'] as $item)<a href="{{ $item['url'] }}" @class(['nav-link', 'active' => $item['active']]) @if($item['active']) aria-current="page" @endif data-nav-item><span>{{ $item['label'] }}</span></a>@endforeach</div>
            </details>
        @endforeach
        <a href="{{ route('admin.seo.edit') }}" @class(['nav-link', 'active' => request()->routeIs('admin.seo.*')]) data-nav-item><x-admin-icon name="book" /><span>SEO page wording</span></a>
        <p id="admin-nav-empty" class="nav-empty" hidden>No pages found. Try “teacher”, “reports” or “support”.</p>
    </nav>

    <div class="sidebar-account">
        <span class="account-avatar" aria-hidden="true">{{ strtoupper(substr((string) config('admin.email', 'A'), 0, 1)) }}</span>
        <div class="account-details"><strong>BSL administrator</strong><small>{{ config('admin.email') ?: 'Secure admin access' }}</small></div>
        <form method="post" action="{{ route('admin.logout') }}">@csrf<button class="sidebar-logout" type="submit" aria-label="Sign out"><x-admin-icon name="logout" /></button></form>
    </div>
</aside>
<button class="sidebar-backdrop" id="admin-nav-backdrop" type="button" aria-label="Close navigation" tabindex="-1" hidden></button>
