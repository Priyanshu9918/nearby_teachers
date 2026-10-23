@extends('admin.layout')

@php
    $section = $section ?? 'subjects';
    $editing = $editing ?? null;
    $labels = ['subjects' => 'Subjects', 'students' => 'Students', 'teachers' => 'Teachers', 'institutes' => 'Institutes'];
    $label = $labels[$section] ?? 'Records';
    $fields = [
        'subjects' => ['domain_id' => 'Subject domain', 'name' => 'Subject name'],
        'students' => ['full_name' => 'Full name', 'email' => 'Email', 'phone' => 'Phone'],
        'teachers' => ['full_name' => 'Full name', 'email' => 'Email', 'phone' => 'Phone', 'bio' => 'Biography', 'rate_per_hour' => 'Hourly rate', 'currency' => 'Currency', 'city' => 'City', 'state' => 'State', 'country' => 'Country', 'country_code' => 'Country code', 'linkedin_url' => 'LinkedIn URL'],
        'institutes' => ['full_name' => 'Institute name', 'email' => 'Email', 'phone' => 'Phone', 'description' => 'Description', 'starting_rate' => 'Starting rate', 'currency' => 'Currency', 'city' => 'City', 'state' => 'State', 'country' => 'Country', 'country_code' => 'Country code', 'instructors_text' => 'Instructors', 'website_url' => 'Website URL'],
    ];
    $sectionFields = $fields[$section] ?? [];
    $columns = match ($section) {
        'subjects' => ['name', 'domain_name', 'is_bsl'],
        'students' => ['full_name', 'email', 'phone', 'created_at'],
        'teachers' => ['full_name', 'email', 'phone', 'bio', 'rate', 'currency', 'city', 'state', 'country', 'linkedin_url', 'created_at'],
        'institutes' => ['full_name', 'email', 'phone', 'bio', 'rate', 'currency', 'city', 'state', 'country', 'instructors_text', 'website_url', 'created_at'],
        default => [],
    };
    $routeSection = $section;
    $count = $records->total();
    $isEditing = $editing !== null;
    $formAction = $isEditing
        ? route('admin.update', ['section' => $routeSection, 'id' => $editing->id])
        : route('admin.store', ['section' => $routeSection]);
@endphp

@section('title', $label)
@section('breadcrumb', $label)

@section('content')
    <div class="page-heading">
        <div>
            <p class="eyebrow">Management</p>
            <h1>{{ $label }}</h1>
            <p>Create and maintain {{ strtolower($label) }} in your platform.</p>
        </div>
        <div class="heading-actions">
        @if ($count !== null)
            <span class="count-pill">{{ number_format($count) }} {{ \Illuminate\Support\Str::plural(strtolower($label), $count) }}</span>
        @endif
            <a class="btn btn-primary" href="{{ route('admin.create', ['section' => $routeSection]) }}">Add {{ \Illuminate\Support\Str::singular(strtolower($label)) }} <x-admin-icon name="arrow" /></a>
        </div>
    </div>

    <details class="panel record-form" @if($isEditing || $errors->any() || request()->routeIs('admin.create')) open @endif>
        <summary class="panel-head">
            <div><h2 id="create-heading">{{ $isEditing ? 'Edit' : 'Add' }} {{ \Illuminate\Support\Str::singular(strtolower($label)) }}</h2><p>{{ $isEditing ? 'Update the record details and save your changes.' : 'Enter the details below to create a new record.' }}</p></div>
            <x-admin-icon name="chevron" />
        </summary>
        <div class="panel-body">
            <form method="post" action="{{ $formAction }}">
                @csrf @if ($isEditing) @method('put') @endif
                <div class="form-grid">
                    @foreach ($sectionFields as $key => $fieldLabel)
                        @php
                            $inputId = 'create-' . $key;
                            $inputType = match ($key) {
                                'email' => 'email',
                                'rate_per_hour', 'starting_rate' => 'number',
                                'linkedin_url', 'website_url' => 'url',
                                default => 'text',
                            };
                            $isRequired = in_array($key, ['name', 'full_name'], true);
                            $isLongText = in_array($key, ['bio', 'description', 'instructors_text'], true);
                            $isInvalid = $errors->has($key);
                            $editingKey = match ($key) {
                                'rate_per_hour', 'starting_rate' => 'rate',
                                default => $key,
                            };
                            $inputValue = old($key, $isEditing ? ($editing->$editingKey ?? '') : ($key === 'currency' ? 'INR' : ''));
                        @endphp
                        <div class="field">
                            <label for="{{ $inputId }}">{{ $fieldLabel }}@if ($isRequired)<span class="required" aria-hidden="true">*</span>@endif</label>
                            @if ($key === 'domain_id')
                                <select id="{{ $inputId }}" name="{{ $key }}" required @class(['invalid' => $isInvalid]) @if ($isInvalid) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif>
                                    <option value="">Choose a domain</option>
                                    @foreach ($domains as $domain)
                                        <option value="{{ $domain->id }}" @selected((string) $inputValue === (string) $domain->id)>{{ $domain->name }}</option>
                                    @endforeach
                                </select>
                            @elseif ($isLongText)
                                <textarea id="{{ $inputId }}" name="{{ $key }}" placeholder="{{ $fieldLabel }}" @class(['invalid' => $isInvalid]) @if ($isInvalid) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif>{{ $inputValue }}</textarea>
                            @else
                                <input id="{{ $inputId }}" name="{{ $key }}" type="{{ $inputType }}" value="{{ $inputValue }}" placeholder="{{ $fieldLabel }}" @if (in_array($key, ['rate_per_hour', 'starting_rate'], true)) min="0" step="0.01" @endif @if ($isRequired) required @endif @class(['invalid' => $isInvalid]) @if ($isInvalid) aria-invalid="true" aria-describedby="{{ $inputId }}-error" @endif>
                            @endif
                            @error($key)<p class="field-error" id="{{ $inputId }}-error">{{ $message }}</p>@enderror
                            @if (in_array($key, ['email', 'phone'], true))
                                @if ($key === 'email') <p class="help">Provide an email or phone number.</p> @endif
                            @endif
                        </div>
                    @endforeach
                </div>
                @if ($section === 'subjects')
                    <label class="check-field"><input type="checkbox" name="is_bsl" value="1" @checked(old('is_bsl', $isEditing && $editing->is_bsl))> This is a BSL subject</label>
                @endif
                <div class="form-actions">
                    <button class="btn btn-primary" type="submit"><span aria-hidden="true">{{ $isEditing ? '✓' : '＋' }}</span> {{ $isEditing ? 'Save changes' : 'Create '.\Illuminate\Support\Str::singular(strtolower($label)) }}</button>
                    @if ($isEditing)<a class="btn" href="{{ route('admin.index', ['section' => $routeSection]) }}">Cancel</a>@endif
                </div>
            </form>
        </div>
    </details>

    <section class="panel" aria-labelledby="records-heading">
        <div class="panel-head">
            <div><h2 id="records-heading">All {{ strtolower($label) }}</h2><p>Review and update existing records.</p></div>
        </div>
        @if (count($records))
            <div class="table-wrap" tabindex="0" role="region" aria-label="{{ $label }} records">
                <table class="data-table">
                    <thead><tr>
                        @foreach ($columns as $column)<th scope="col">{{ str_replace('_', ' ', ucfirst($column)) }}</th>@endforeach
                        <th scope="col">Actions</th>
                    </tr></thead>
                    <tbody>
                    @foreach ($records as $record)
                        <tr>
                            @foreach ($columns as $column)
                                @if ($column === 'is_bsl')
                                    <td>@if ($record->$column)<span class="badge">BSL</span>@else<span>—</span>@endif</td>
                                @else
                                    <x-admin-table-cell :column="$column" :value="$column === 'created_at' && $record->$column ? \Illuminate\Support\Carbon::parse($record->$column)->format('M j, Y') : ($record->$column ?? null)" />
                                @endif
                            @endforeach
                            <td>
                                <div class="table-actions">
                                    @if ($section !== 'subjects')<a class="btn btn-small" href="{{ route('admin.reports.user', $record->id) }}">Full report</a>@endif
                                    <a class="btn btn-small" href="{{ route('admin.edit', ['section' => $routeSection, 'id' => $record->id]) }}">Edit</a>
                                    <form method="post" action="{{ route('admin.destroy', ['section' => $routeSection, 'id' => $record->id]) }}" onsubmit="return confirm('Delete this record? This action cannot be undone.')">
                                        @csrf @method('delete')
                                        <button class="btn btn-small btn-danger" type="submit">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @else
            @if ($records->total() > 0)
                <div class="empty-state"><strong>No records on this page.</strong><p><a href="{{ $records->url(1) }}">Return to the first page</a></p></div>
            @else
            <div class="empty-state"><div class="empty-icon" aria-hidden="true">◇</div><strong>No {{ strtolower($label) }} yet</strong><p>Use the form above to add your first record.</p></div>
            @endif
        @endif
        @include('admin.partials.pagination', ['page' => $records])
    </section>
@endsection
