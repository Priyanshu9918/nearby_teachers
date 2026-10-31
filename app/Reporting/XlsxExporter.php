<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

final class XlsxExporter
{
    private const MAX_CELL_CHARACTERS = 32000;

    /** @param array<string, ReportDefinition> $reports */
    public function downloadAll(array $reports, callable $buildQuery): BinaryFileResponse
    {
        if (! class_exists(ZipArchive::class)) {
            throw new \RuntimeException('The ZIP extension is required to build an Excel workbook.');
        }

        $temporaryBase = tempnam(sys_get_temp_dir(), 'nearby-reports-');
        if ($temporaryBase === false) {
            throw new \RuntimeException('Could not create a temporary workbook file.');
        }
        $workbookPath = $temporaryBase.'.xlsx';
        @unlink($temporaryBase);
        $sheetFiles = [];

        try {
            $sheetDefinitions = [];
            $usedNames = [];
            foreach ($reports as $key => $report) {
                $sheetDefinitions[] = [
                    'key' => $key,
                    'name' => $this->uniqueSheetName($report->title ?: $key, $usedNames),
                ];
            }
            $longTextName = $this->uniqueSheetName('Long text', $usedNames);
            $zip = new ZipArchive;
            if ($zip->open($workbookPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not open the Excel workbook for writing.');
            }

            $longTextFile = $this->createSheetFile($sheetFiles);
            $longTextPath = $sheetFiles[array_key_last($sheetFiles)];
            $longTextRow = 1;
            $this->writeRow($longTextFile, $longTextRow++, ['Report', 'Record ID', 'Column', 'Part', 'Text']);

            foreach ($reports as $index => $report) {
                [$query, $columns] = $buildQuery($report->key, $report);
                if (! $query instanceof Builder) {
                    throw new \LogicException('The workbook query factory must return a query builder.');
                }
                $sheetFile = $this->createSheetFile($sheetFiles);
                $rowIndex = 1;
                $this->writeRow($sheetFile, $rowIndex++, $columns);

                foreach ($query->lazy(500) as $record) {
                    $row = [];
                    foreach ($columns as $column) {
                        $value = $this->stringValue($record->$column ?? null);
                        if ($this->characterLength($value) <= self::MAX_CELL_CHARACTERS) {
                            $row[] = $value;

                            continue;
                        }

                        $parts = $this->splitText($value);
                        $row[] = 'Continued in '.$longTextName.' row '.$longTextRow;
                        foreach ($parts as $partIndex => $part) {
                            $this->writeRow($longTextFile, $longTextRow++, [
                                $report->title,
                                $this->stringValue($record->id ?? ''),
                                $column,
                                (string) ($partIndex + 1),
                                $part,
                            ]);
                        }
                    }
                    $this->writeRow($sheetFile, $rowIndex++, $row);
                }
                fclose($sheetFile);
            }
            fclose($longTextFile);

            $sheetCount = count($reports) + 1;
            $zip->addFromString('[Content_Types].xml', $this->contentTypes($sheetCount));
            $zip->addFromString('_rels/.rels', $this->rootRelationships());
            $zip->addFromString('xl/workbook.xml', $this->workbookXml($sheetDefinitions, $longTextName));
            $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationships($sheetCount));
            $sheetIndex = 1;
            foreach ($sheetFiles as $path) {
                if ($path === $longTextPath) {
                    continue;
                }
                $zip->addFile($path, 'xl/worksheets/sheet'.$sheetIndex++.'.xml');
            }
            $zip->addFile($longTextPath, 'xl/worksheets/sheet'.$sheetIndex.'.xml');
            if (! $zip->close()) {
                throw new \RuntimeException('Could not finish the Excel workbook.');
            }

            return response()->download($workbookPath, 'nearby-teacher-all-reports-'.now()->format('Y-m-d').'.xlsx', [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Cache-Control' => 'no-store, private',
                'X-Content-Type-Options' => 'nosniff',
            ])->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            @unlink($workbookPath);
            throw $exception;
        } finally {
            foreach ($sheetFiles as $path) {
                @unlink($path);
            }
        }
    }

    /** @param resource $stream */
    private function writeRow($stream, int $rowNumber, array $values): void
    {
        $xml = '<row r="'.$rowNumber.'">';
        foreach (array_values($values) as $index => $value) {
            $cell = $this->columnName($index + 1).$rowNumber;
            $xml .= '<c r="'.$cell.'" t="inlineStr"><is><t xml:space="preserve">'.$this->xml($this->stringValue($value)).'</t></is></c>';
        }
        fwrite($stream, $xml.'</row>');
    }

    /** @param array<int, string> $sheetFiles @return resource */
    private function createSheetFile(array &$sheetFiles)
    {
        $path = tempnam(sys_get_temp_dir(), 'nearby-sheet-');
        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary Excel sheet.');
        }
        $sheetFiles[] = $path;
        $stream = fopen($path, 'wb');
        if ($stream === false) {
            throw new \RuntimeException('Could not write a temporary Excel sheet.');
        }
        fwrite($stream, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>');

        return $stream;
    }

    private function uniqueSheetName(string $name, array &$usedNames): string
    {
        $name = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', trim($name)) ?? 'Report';
        $name = trim($name, " '\t\n\r\0\x0B");
        $name = $name === '' ? 'Report' : mb_substr($name, 0, 31, 'UTF-8');
        $candidate = $name;
        $suffix = 2;
        while (in_array(mb_strtolower($candidate, 'UTF-8'), $usedNames, true)) {
            $tail = ' ('.$suffix++.')';
            $candidate = mb_substr($name, 0, 31 - mb_strlen($tail, 'UTF-8'), 'UTF-8').$tail;
        }
        $usedNames[] = mb_strtolower($candidate, 'UTF-8');

        return $candidate;
    }

    private function stringValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) ?: '';
        }

        return (string) $value;
    }

    private function characterLength(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    private function splitText(string $value): array
    {
        return mb_str_split($value, self::MAX_CELL_CHARACTERS, 'UTF-8');
    }

    private function xml(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value) ?? '';

        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $name = chr(65 + $remainder).$name;
            $number = intdiv($number - 1, 26);
        }

        return $name;
    }

    private function contentTypes(int $sheetCount): string
    {
        $sheets = '';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.$sheets.'</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private function workbookXml(array $sheets, string $longTextName): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($sheets as $index => $sheet) {
            $xml .= '<sheet name="'.$this->xml($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';
        }
        $xml .= '<sheet name="'.$this->xml($longTextName).'" sheetId="'.(count($sheets) + 1).'" r:id="rId'.(count($sheets) + 1).'"/></sheets></workbook>';

        return $xml;
    }

    private function workbookRelationships(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
        }

        return $xml.'</Relationships>';
    }
}
