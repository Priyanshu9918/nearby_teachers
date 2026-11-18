<?php

namespace Tests\Unit;

use App\Http\Controllers\Api\OperationsController;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use App\Services\SupportTicketManager;
use App\Services\SupportTicketScreenshot;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SupportTicketScreenshotTest extends TestCase
{
    private function image(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('screen.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    }

    public function test_optional_image_validation_rejects_nonimages_svg_and_oversized_files(): void
    {
        $rules = ['screenshot' => SupportTicketScreenshot::rules()];
        $this->assertTrue(validator([], $rules)->passes());
        $this->assertTrue(validator(['screenshot' => null], $rules)->passes());
        $this->assertTrue(validator(['screenshot' => $this->image()], $rules)->passes());
        foreach ([
            UploadedFile::fake()->createWithContent('fake.png', 'not an image'),
            UploadedFile::fake()->createWithContent('screen.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            UploadedFile::fake()->createWithContent('huge.png', $this->image()->getContent().str_repeat('x', 5120 * 1024)),
        ] as $file) {
            $upload = new UploadedFile($file->getPathname(), $file->getClientOriginalName(), null, null, true);
            $this->assertFalse(validator(['screenshot' => $upload], $rules)->passes(), $file->getClientOriginalName());
        }
    }

    public function test_payload_uses_an_authenticated_url_and_does_not_expose_private_storage_paths(): void
    {
        $source = (object) ['id' => 42, 'screenshot_path' => 'support-screenshots/private.png', 'status' => 'pending'];
        $payload = SupportTicketScreenshot::payload($source);
        $this->assertTrue($payload->screenshotAvailable);
        $this->assertSame(route('support.screenshot', ['id' => 42]), $payload->screenshotUrl);
        $this->assertObjectNotHasProperty('screenshot_path', $payload);
        $this->assertObjectHasProperty('screenshot_path', $source);
        $empty = SupportTicketScreenshot::payload((object) ['id' => 43, 'screenshot_path' => null]);
        $this->assertFalse($empty->screenshotAvailable);
        $this->assertNull($empty->screenshotUrl);
    }

    public static function viewers(): array
    {
        return [[7, false, 200], [8, false, 404], [8, true, 200]];
    }

    #[DataProvider('viewers')]
    public function test_only_ticket_owner_or_admin_can_read_private_screenshot(int $viewer, bool $admin, int $status): void
    {
        Storage::fake('local');
        $path = $this->image()->store('support-screenshots', 'local');
        $query = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('support_tickets')->once()->andReturn($query);
        $query->shouldReceive('where')->with('id', 42)->once()->andReturnSelf();
        $query->shouldReceive('first')->once()->andReturn((object) ['id' => 42, 'user_id' => 7, 'screenshot_path' => $path]);
        if ($admin) {
            $access = Mockery::mock(Builder::class);
            DB::shouldReceive('table')->with('admin_report_access_log')->once()->andReturn($access);
            $access->shouldReceive('insert')->once()->with(Mockery::on(fn ($row) => $row['action'] === 'api-screenshot-view' && $row['record_id'] === 42))->andReturn(true);
        }
        $request = Request::create('/support/42/screenshot', 'GET');
        $request->attributes->set('authenticated_user', (object) ['id' => $viewer, 'is_admin' => $admin]);
        try {
            $response = app(OperationsController::class)->ticketScreenshot($request, 42);
            $this->assertSame(200, $status);
            $this->assertSame('image/png', $response->headers->get('Content-Type'));
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        } catch (HttpException $error) {
            $this->assertSame($status, $error->getStatusCode());
        }
    }

    public function test_failed_ticket_transaction_cleans_up_the_private_file(): void
    {
        Storage::fake('local');
        DB::shouldReceive('transaction')->once()->andThrow(new \RuntimeException('Database unavailable'));
        try {
            app(SupportTicketManager::class)->create(7, 'Help', 'Please help', $this->image());
            $this->fail('The ticket must not succeed.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Database unavailable', $error->getMessage());
        }
        $this->assertSame([], Storage::disk('local')->allFiles('support-screenshots'));
    }

    public function test_ticket_creation_stores_a_private_image_and_records_only_attachment_presence_in_history(): void
    {
        Storage::fake('local');
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('transactionLevel')->andReturn(1);
        $saved = [];
        $tickets = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('support_tickets')->andReturn($tickets);
        $tickets->shouldReceive('insertGetId')->once()->andReturnUsing(function ($row) use (&$saved) {
            $saved = $row;

            return 42;
        });
        $tickets->shouldReceive('where')->with('id', 42)->once()->andReturnSelf();
        $tickets->shouldReceive('first')->once()->andReturnUsing(function () use (&$saved) {
            return (object) ['id' => 42, ...$saved];
        });
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->andReturn($history);
        $events = [];
        $history->shouldReceive('insertGetId')->twice()->andReturnUsing(function ($row) use (&$events) {
            $events[$row['event']] = json_decode($row['after_data'], true);

            return count($events);
        });
        $ticket = app(SupportTicketManager::class)->create(7, 'Private title', 'Private details', $this->image());
        Storage::disk('local')->assertExists($ticket->screenshot_path);
        $this->assertSame(['status' => 'pending'], $events['support_ticket.submitted']);
        $this->assertSame(['has_screenshot' => true], $events['support_ticket.screenshot_attached']);
        $this->assertStringNotContainsString('Private', json_encode($events));
        $this->assertStringNotContainsString('support-screenshots', json_encode($events));
    }

    public function test_support_report_exposes_attachment_presence_without_storage_paths(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $report = app(ReportRegistry::class)->get('support');
        [$query, $columns] = app(ReportQuery::class)->build($report, ['status' => 'pending']);
        $this->assertContains('screenshot_available', $columns);
        $this->assertNotContains('screenshot_path', $columns);
        $this->assertStringContainsString('CASE WHEN r.screenshot_path IS NOT NULL', $query->toSql());
        $this->assertContains('pending', $query->getBindings());
    }

    public function test_screenshot_endpoints_require_authentication(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        $this->getJson('/support/42/screenshot')->assertUnauthorized();
        $this->get('/admin/support/tickets/42/screenshot')->assertRedirect(route('admin.login'));
    }

    public function test_screenshot_path_cannot_traverse_outside_its_private_directory(): void
    {
        $this->expectException(HttpException::class);
        SupportTicketScreenshot::response('support-screenshots/../secret.png');
    }
}
