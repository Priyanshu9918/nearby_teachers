<?php

namespace App\Reporting;

final class ReportRegistry
{
    /** @return array<string, ReportDefinition> */
    public function all(): array
    {
        $reports = [];
        foreach (config('reports', []) as $key => $definition) {
            $reports[$key] = new ReportDefinition(...['key' => $key, ...$definition]);
        }

        return $reports;
    }

    public function get(string $key): ReportDefinition
    {
        $reports = $this->all();
        abort_unless(isset($reports[$key]), 404);

        return $reports[$key];
    }
}
