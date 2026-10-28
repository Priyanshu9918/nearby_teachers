@inject('adminNavigation', 'App\Support\AdminNavigation')
@php($navigationGroups = $adminNavigation->groups())
<aside class="sidebar" id="admin-sidebar" aria-label="Admin sidebar">
    <div class="sidebar-brand-row"><a class="brand" href="{{ route('admin.dashboard') }}"><span class="brand-mark"><x-admin-icon name="book" /></span><span><strong>Nearby Teacher</strong><small>ADMIN WORKSPACE</small></span></a><button type="button" class="icon-button mobile-only" id="close-admin-nav" aria-label="Close navigation"><x-admin-icon name="close" /></button></div>
    <div class="nav-search"><x-admin-icon name="search" /><input id="admin-nav-search" type="search" placeholder="Find a page…" aria-label="Find an admin page" autocomplete="off"></div>
    <nav class="side-nav" aria-label="Admin sections">
        <div class="nav-section-label">Workspace</div>
        <a href="{{ route('admin.dashboard') }}" @class(['nav-link', 'active' => request()->routeIs('admin.dashboard')]) @if(request()->routeIs('admin.dashboard')) aria-current="page" @endif data-nav-item><x-admin-icon name="dashboard" /><span>Overview</span></a>
        <a class="nav-link" href="{{ route('admin.dashboard') }}#report-library" data-nav-item><x-admin-icon name="document" /><span>All reports</span></a>
        <div class="nav-section-label">Manage accounts & catalog</div>
        @foreach (['students' => ['Students', 'user'], 'teachers' => ['Teachers', 'users'], 'institutes' => ['Institutes', 'building'], 'subjects' => ['Subjects', 'book']] as $key => [$label, $icon])
            @php($active = (request()->route('section') ?? ($section ?? null)) === $key)
            <a href="{{ route('admin.index', ['section' => $key]) }}" @class(['nav-link', 'active' => $active]) @if($active) aria-current="page" @endif data-nav-item><x-admin-icon :name="$icon" /><span>{{ $label }}</span></a>
        @endforeach
        <div class="nav-section-label">Explore reports</div>
        <a class="nav-link" href="{{ route('admin.seo.edit') }}" data-nav-item><x-admin-icon name="book" /><span>SEO subject wording</span></a>
        @foreach ($navigationGroups as $label => $group)
            @php($groupActive = collect($group['items'])->contains('active', true))
            <details class="nav-group" @if($groupActive || $label === 'Chat & lessons') open @endif data-nav-group>
                <summary @class(['nav-group-title', 'group-active' => $groupActive])><x-admin-icon :name="$group['icon']" /><span>{{ $label }}</span><x-admin-icon class="nav-chevron" name="chevron" /></summary>
                <div class="nav-group-items">@foreach ($group['items'] as $item)<a href="{{ $item['url'] }}" @class(['nav-link', 'active' => $item['active']]) @if($item['active']) aria-current="page" @endif data-nav-item><span>{{ $item['label'] }}</span></a>@endforeach</div>
            </details>
        @endforeach
        <p id="admin-nav-empty" class="nav-empty" hidden>No pages found. Try “chat”, “users” or “bookings”.</p>
    </nav>
    <div class="sidebar-bottom"><span class="workspace-dot"></span><div><strong>Administration</strong><small>Manage. Review. Stay connected.</small></div></div>
</aside>
<button class="sidebar-backdrop" id="admin-nav-backdrop" type="button" aria-label="Close navigation" tabindex="-1" hidden></button>
