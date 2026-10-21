<?php

namespace App\Reporting;

final readonly class ReportDefinition
{
    public function __construct(
        public string $key,
        public string $title,
        public string $table,
        public array $columns,
        public ?string $timestamp = null,
        public array $participants = [],
        public array $search = [],
        public array $computed = [],
    ) {}
}
