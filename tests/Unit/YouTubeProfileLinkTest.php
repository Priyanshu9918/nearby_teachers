<?php

namespace Tests\Unit;

use App\Reporting\CsvExporter;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use App\Services\YouTubeProfileLink;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\LazyCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YouTubeProfileLinkTest extends TestCase
{
    public static function links(): array
    {
        return [
            ['https://www.youtube.com/@teacher', true],
            ['https://youtube.com/channel/UCexample', true],
            ['https://m.youtube.com/watch?v=example', true],
            ['https://youtu.be/example', true],
            [null, true],
            ['https://youtube.com.evil.test/channel', false],
            ['https://notyoutube.com/watch', false],
            ['https://youtube.com@evil.test/watch', false],
            ['https://user:secret@youtube.com/watch', false],
            ['javascript:alert(1)', false],
            ['ftp://youtube.com/watch', false],
            ['youtube.com/@teacher', false],
            ['https://youtube.com/'.str_repeat('a', 2048), false],
        ];
    }

    #[DataProvider('links')]
    public function test_only_optional_youtube_web_links_are_accepted(?string $url, bool $valid): void
    {
        $validator = validator(['youtubeUrl' => $url], ['youtubeUrl' => YouTubeProfileLink::rules()]);
        $this->assertSame($valid, $validator->passes());
    }

    public function test_omitting_the_optional_link_is_valid(): void
    {
        $this->assertTrue(validator([], ['youtubeUrl' => YouTubeProfileLink::rules()])->passes());
    }

    public function test_both_profile_reports_search_and_export_the_explicit_link_column(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        foreach (['teacher-profiles', 'institute-profiles'] as $key) {
            $report = app(ReportRegistry::class)->get($key);
            [$query, $columns] = app(ReportQuery::class)->build($report, ['q' => '@example']);
            $this->assertContains('youtube_url', $columns);
            $this->assertStringContainsString('"r"."youtube_url" like ?', $query->toSql());
            $this->assertContains('%@example%', $query->getBindings());
            $streamQuery = \Mockery::mock(Builder::class);
            $streamQuery->shouldReceive('lazy')->with(500)->once()->andReturn(
                LazyCollection::make([(object) ['youtube_url' => 'https://youtube.com/@example']])
            );
            $response = app(CsvExporter::class)->download($streamQuery, $columns, $key);
            ob_start();
            try {
                $response->sendContent();
                $csv = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertStringContainsString('youtube_url', $csv);
            $this->assertStringContainsString('https://youtube.com/@example', $csv);
        }
    }

    public function test_profile_report_reads_and_exports_still_require_an_admin_session(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        foreach (['teacher-profiles', 'institute-profiles'] as $key) {
            $this->get('/admin/reports/'.$key)->assertRedirect(route('admin.login'));
            $this->get('/admin/reports/'.$key.'?export=1')->assertRedirect(route('admin.login'));
        }
    }
}
