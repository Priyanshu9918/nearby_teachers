<form id="directory-filter-form" method="get" action="{{ request()->url() }}" class="directory-search {{ $countryLanding ? 'country-search-form' : '' }}" role="search">
    @if($language !== \App\Services\SeoDirectory::language($page->country))<input type="hidden" name="lang" value="{{ $language }}">@endif
    <label class="sr-only" for="teacher-search">{{ $labels['search_label'] }}</label>
    <input id="teacher-search" name="q" type="search" maxlength="100" value="{{ $searchTerm }}" placeholder="{{ $labels['search_placeholder'] }}" autocomplete="off">
    @if($countryLanding)<div class="country-search-options">@endif
        <label class="sr-only" for="teacher-mode">{{ $labels['modes'] }}</label>
        <select id="teacher-mode" name="mode" aria-label="{{ $labels['modes'] }}">
            <option value="">{{ $labels['all_modes'] }}</option>
            <option value="home_tuition" @selected(($filters['mode'] ?? '') === 'home_tuition')>{{ $labels['home_tuition'] }}</option>
            <option value="online" @selected(($filters['mode'] ?? '') === 'online')>{{ $labels['online'] }}</option>
            <option value="in_person" @selected(($filters['mode'] ?? '') === 'in_person')>{{ $labels['at_centre'] }}</option>
        </select>
        <label class="country-search-trial"><input id="teacher-trial" type="checkbox" name="trial" value="1" @checked($trialOnly)> {{ $labels['trial_only'] }}</label>
    @if($countryLanding)</div>@endif
    <button type="submit">{{ $labels['apply_filters'] }}</button>
    @if($hasActiveFilters)<a class="directory-clear" href="{{ $clearFiltersUrl }}">{{ $labels['clear_filters'] }}</a>@endif
</form>
