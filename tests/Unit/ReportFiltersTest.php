<?php

namespace Tests\Unit;

use App\Reporting\ReportFilters;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportFiltersTest extends TestCase
{
    public function test_quick_range_overrides_old_date_inputs_and_limits_query_to_inclusive_days(): void
    {
        config(['app.timezone' => 'UTC']);
        Carbon::setTestNow('2026-10-31 12:00:00');

        try {
            $filters = app(ReportFilters::class)->validate(Request::create('/admin/reports/seo-location-pages', 'GET', [
                'range' => 'last_7_days',
                'from' => 'invalid previous input',
                'to' => '2026-01-01',
                'q' => 'Noida',
            ]), 'seo-location-pages');

            $this->assertSame('Noida', $filters['q']);
            $this->assertSame('2026-10-25', $filters['from']);
            $this->assertSame('2026-10-31', $filters['to']);

            $query = (new Builder(new Connection(fn () => null, 'report_filters_test')))->from('records');
            app(ReportFilters::class)->dates($query, 'created_at', $filters);
            $this->assertCount(2, $query->getBindings());
            $this->assertSame('2026-10-25 00:00:00', $query->getBindings()[0]->format('Y-m-d H:i:s'));
            $this->assertSame('2026-11-01 00:00:00', $query->getBindings()[1]->format('Y-m-d H:i:s'));
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_manual_reverse_date_range_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(ReportFilters::class)->validate(Request::create('/admin/reports/users', 'GET', [
            'from' => '2026-10-31',
            'to' => '2026-10-30',
        ]), 'users');
    }

    public function test_seo_location_search_and_dates_are_applied_to_the_report_query(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'app.timezone' => 'UTC',
        ]);
        $filters = app(ReportFilters::class)->validate(Request::create('/admin/reports/seo-location-pages', 'GET', [
            'q' => 'Noida',
            'from' => '2026-10-20',
            'to' => '2026-10-21',
        ]), 'seo-location-pages');

        [$query] = app(ReportQuery::class)->build(app(ReportRegistry::class)->get('seo-location-pages'), $filters);
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        $this->assertStringContainsString('"r"."path" like ?', $sql);
        $this->assertStringContainsString('"r"."created_at" >= ?', $sql);
        $this->assertStringContainsString('"r"."created_at" < ?', $sql);
        $this->assertContains('%Noida%', $bindings);
        $this->assertSame('2026-10-20 00:00:00', $bindings[count($bindings) - 2]->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 00:00:00', $bindings[count($bindings) - 1]->format('Y-m-d H:i:s'));
    }
}
