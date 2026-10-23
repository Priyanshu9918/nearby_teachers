@php
    $current = $page->currentPage();
    $last = $page->lastPage();
    $numbers = array_values(array_unique(array_filter([1, $current - 2, $current - 1, $current, $current + 1, $current + 2, $last], fn ($number) => $number >= 1 && $number <= $last)));
    sort($numbers);
    $previous = null;
@endphp
<div class="pagination-bar">
    <div class="pagination-summary" role="status">
        @if ($page->count())Showing {{ number_format($page->firstItem()) }}–{{ number_format($page->lastItem()) }} of {{ number_format($page->total()) }} records
        @else Showing 0 of {{ number_format($page->total()) }} records @endif
        <span>Page {{ number_format($current) }} of {{ number_format($last) }}</span>
    </div>
    <form class="pagination-size" method="get" action="{{ url()->current() }}">
        @foreach (request()->except(['page', 'per_page', 'export']) as $key => $value)
            @if (is_scalar($value))<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endif
        @endforeach
        <label for="rows-per-page">Rows per page</label>
        <select id="rows-per-page" name="per_page" aria-label="Rows per page">
            @foreach ([15, 25, 30, 50, 100] as $size)<option value="{{ $size }}" @selected($page->perPage() === $size)>{{ $size }}</option>@endforeach
        </select>
        <button class="pagination-apply" type="submit">Apply</button>
    </form>
    <nav class="pagination-links" aria-label="Records pagination">
        @if ($page->previousPageUrl())<a class="pagination-link pagination-direction pagination-previous" href="{{ $page->previousPageUrl() }}" rel="prev" aria-label="Previous page"><x-admin-icon name="chevron" class="pagination-back-icon" /><span class="pagination-label">Previous</span></a>
        @else <span class="pagination-link pagination-direction pagination-disabled" aria-disabled="true" aria-label="Previous page"><x-admin-icon name="chevron" class="pagination-back-icon" /><span class="pagination-label">Previous</span></span> @endif
        @foreach ($numbers as $number)
            @if ($previous !== null && $number > $previous + 1)<span class="pagination-gap" aria-hidden="true">…</span>@endif
            @if ($number === $current)<span class="pagination-link pagination-current" aria-current="page" aria-label="Page {{ $number }}">{{ $number }}</span>
            @else <a @class(['pagination-link', 'pagination-distance-2' => abs($number - $current) === 2 && $number !== 1 && $number !== $last]) href="{{ $page->url($number) }}" aria-label="Page {{ $number }}">{{ $number }}</a> @endif
            @php($previous = $number)
        @endforeach
        @if ($page->nextPageUrl())<a class="pagination-link pagination-direction pagination-next" href="{{ $page->nextPageUrl() }}" rel="next" aria-label="Next page"><span class="pagination-label">Next</span><x-admin-icon name="chevron" /></a>
        @else <span class="pagination-link pagination-direction pagination-disabled" aria-disabled="true" aria-label="Next page"><span class="pagination-label">Next</span><x-admin-icon name="chevron" /></span> @endif
    </nav>
</div>
