<?php

namespace Tests\Feature;

use App\Reporting\ReportDefinition;
use App\Reporting\XlsxExporter;
use Illuminate\Database\Query\Builder;
use Mockery;
use Tests\TestCase;
use ZipArchive;

class XlsxExporterTest extends TestCase
{
    public function test_workbook_is_valid_and_preserves_long_text_without_excel_formulas(): void
    {
        $longText = str_repeat('Long report detail ', 1800);
        $query = Mockery::mock(Builder::class);
        $query->shouldReceive('lazy')->once()->with(500)->andReturn(collect([
            (object) ['id' => '12345678901234567890', 'full_name' => '=1+1'],
            (object) ['id' => '12345678901234567891', 'full_name' => $longText],
        ]));
        $definition = new ReportDefinition('users', 'Users and registrations', 'users', ['id', 'full_name']);

        $response = app(XlsxExporter::class)->downloadAll(
            ['users' => $definition],
            static fn () => [$query, ['id', 'full_name']],
        );
        $path = $response->getFile()->getPathname();
        try {
            $workbook = new ZipArchive;
            $this->assertNotFalse($workbook->open($path, ZipArchive::CHECKCONS));
            $this->assertNotFalse($workbook->getFromName('xl/workbook.xml'));
            $usersSheet = $workbook->getFromName('xl/worksheets/sheet1.xml');
            $longTextSheet = $workbook->getFromName('xl/worksheets/sheet2.xml');
            $this->assertStringContainsString('12345678901234567890', $usersSheet);
            $this->assertStringContainsString('=1+1', $usersSheet);
            $this->assertStringContainsString('Continued in Long text row 2', $usersSheet);
            $this->assertStringContainsString('Long report detail', $longTextSheet);
            $this->assertStringContainsString('inlineStr', $usersSheet);
            $workbook->close();
        } finally {
            @unlink($path);
        }
    }
}
