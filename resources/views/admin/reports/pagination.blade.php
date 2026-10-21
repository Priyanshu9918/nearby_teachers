<div class="panel-body" style="display:flex;gap:12px;align-items:center">
    <span>{{ number_format($page->total()) }} records · page {{ $page->currentPage() }} of {{ $page->lastPage() }}</span>
    @if ($page->previousPageUrl())<a class="btn" href="{{ $page->previousPageUrl() }}">Previous</a>@endif
    @if ($page->nextPageUrl())<a class="btn" href="{{ $page->nextPageUrl() }}">Next</a>@endif
</div>
