<form method="get" class="panel panel-body">
    <div class="form-grid">
        <div class="field"><label for="q">Search</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" placeholder="{{ isset($report) ? 'Name, email, phone, record ID or report text' : 'Message text' }}"></div>
        @if (!empty($userColumns))
        <div class="field"><label for="type">Participant account type</label><select name="type" id="type"><option value="">All types</option>@foreach (['learner' => 'Student', 'teacher' => 'Teacher', 'institute' => 'Institute'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
        <div class="field"><label for="user">User ID</label><input name="user" id="user" value="{{ $filters['user'] ?? '' }}"></div>
        @endif
        @if (!isset($report) || $timestamp !== null)
        <div class="field"><label for="from">From date</label><input type="date" name="from" id="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="field"><label for="to">Through date</label><input type="date" name="to" id="to" value="{{ $filters['to'] ?? '' }}"></div>
        @endif
    </div>
    <div class="form-actions"><button class="btn btn-primary">Apply filters</button><a class="btn" href="{{ request()->url() }}">Reset</a><button class="btn" name="export" value="1">Export matching records CSV</button></div>
    <p class="help">Dates use {{ config('app.timezone') }}. Exports include every matching row, across all pages.</p>
</form>
