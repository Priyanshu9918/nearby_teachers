@props(['column', 'value' => null, 'userLink' => false])
@php
    $isId = $column === 'id' || str_ends_with($column, '_id');
    $isDate = str_ends_with($column, '_at') || in_array($column, ['requested_time', 'day'], true);
    $isCompact = $isId || $isDate || in_array($column, ['event', 'version', 'entity_type', 'account_type', 'status', 'type'], true);
    $text = $value === null ? '—' : (string) $value;
@endphp
<td @class(['cell-id' => $isId, 'cell-date' => $isDate, 'cell-compact' => $isCompact, 'cell-text' => ! $isCompact])>
    @if ($userLink && $value !== null)
        <a class="record-id" href="{{ route('admin.reports.user', $value) }}">{{ $text }}</a>
    @elseif ($isId)
        <span class="record-id">{{ $text }}</span>
    @elseif ($isCompact)
        {{ $text }}
    @elseif (mb_strlen($text) > 160)
        <details class="cell-value cell-expanded"><summary>{{ \Illuminate\Support\Str::limit($text, 120) }}</summary><div>{{ $text }}</div></details>
    @else
        <span @class(['cell-value', 'record-name' => in_array($column, ['name', 'full_name'], true)])>{{ $text }}</span>
    @endif
</td>
