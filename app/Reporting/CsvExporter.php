<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class CsvExporter
{
    public function download(Builder $query, array $columns, string $name): StreamedResponse
    {
        return response()->streamDownload(function () use ($query, $columns): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $columns, ',', '"', '');
            foreach ($query->lazy(500) as $record) {
                $row = array_map(function ($column) use ($record) {
                    $value = (string) ($record->$column ?? '');

                    return preg_match('/^[\s]*[=+@\-]/u', $value) ? "'".$value : $value;
                }, $columns);
                fputcsv($stream, $row, ',', '"', '');
            }
            fclose($stream);
        }, $name.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
