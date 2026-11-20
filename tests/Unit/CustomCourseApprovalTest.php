<?php

namespace Tests\Unit;

use App\Reporting\CsvExporter;
use App\Reporting\ReportFilters;
use App\Reporting\ReportQuery;
use App\Reporting\ReportRegistry;
use App\Services\CustomSubjectApproval;
use App\Services\InstitutePublicPages;
use App\Services\TeacherPublicPages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\ViewErrorBag;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CustomCourseApprovalTest extends TestCase
{
    private function submissionQueries(?object $subject = null, ?object $pending = null): Builder
    {
        DB::shouldReceive('transactionLevel')->andReturn(1);
        $requests = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('custom_subject_requests')->andReturn($requests);
        $requests->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $requests->shouldReceive('get')->once()->andReturn(collect());
        $requests->shouldReceive('first')->andReturn($pending);
        $domain = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('subject_domains')->andReturn($domain);
        $domain->shouldReceive('insertOrIgnore')->once()->andReturn(0);
        $domain->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $domain->shouldReceive('first')->once()->andReturn((object) ['id' => 5]);
        $subjects = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('subjects')->andReturn($subjects);
        $subjects->shouldReceive('where', 'whereRaw')->andReturnSelf();
        $subjects->shouldReceive('first')->once()->andReturn($subject);
        $subjects->shouldReceive('insertGetId', 'insert')->never();

        return $requests;
    }

    public function test_unknown_names_are_deduplicated_and_only_saved_as_pending_requests(): void
    {
        $requests = $this->submissionQueries();
        $requests->shouldReceive('insertGetId')->once()->with(Mockery::on(fn ($row) => $row['name'] === 'New Course'
            && $row['profile_type'] === 'teacher' && $row['status'] === 'pending' && $row['user_id'] === 42))->andReturn(90);
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->once()->andReturn($history);
        $history->shouldReceive('insertGetId')->once()->with(Mockery::on(fn ($row) => $row['event'] === 'course_request.submitted'
            && $row['entity_type'] === 'custom_subject_request' && $row['entity_id'] === 90 && $row['version'] === 1))->andReturn(1);
        $data = app(CustomSubjectApproval::class)->resolve([
            'primarySubjects' => [7], 'otherSubjects' => [' New   Course ', 'new course'],
        ], 'primarySubjects', 42, 5);
        $this->assertSame([7], $data['primarySubjects']);
    }

    public function test_existing_catalogue_courses_can_be_selected_without_another_approval(): void
    {
        $requests = $this->submissionQueries((object) ['id' => 17]);
        $requests->shouldReceive('insertGetId', 'update')->never();
        $data = app(CustomSubjectApproval::class)->resolve(['subjectIds' => [], 'otherSubjects' => ['Known Course']], 'subjectIds', 42, 5);
        $this->assertSame([17], $data['subjectIds']);
    }

    public function test_resubmitting_the_same_pending_name_does_not_create_a_duplicate(): void
    {
        $requests = $this->submissionQueries(null, (object) ['id' => 90, 'status' => 'pending']);
        $requests->shouldReceive('insertGetId', 'update')->never();
        $data = app(CustomSubjectApproval::class)->resolve(['subjectIds' => [], 'otherSubjects' => ['New Course']], 'subjectIds', 42, 5);
        $this->assertSame([], $data['subjectIds']);
    }

    public function test_job_submissions_return_private_pending_references_instead_of_public_subject_ids(): void
    {
        $requests = $this->submissionQueries();
        $requests->shouldReceive('insertGetId')->once()->with(Mockery::on(fn ($row) => $row['profile_type'] === 'job'))->andReturn(90);
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->once()->andReturn($history);
        $history->shouldReceive('insertGetId')->once()->andReturn(1);
        $data = app(CustomSubjectApproval::class)->resolve(['courseIds' => [], 'otherSubjects' => ['New Course']], 'courseIds', 42, 5);
        $this->assertSame([], $data['courseIds']);
        $this->assertSame([['request_id' => 90, 'name' => 'New Course']], $data['_pendingCourses']);
    }

    public static function existingSubjects(): array
    {
        return [[null, 'job'], [17, 'job'], [null, 'teacher'], [17, 'teacher'], [null, 'institute'], [17, 'institute']];
    }

    #[DataProvider('existingSubjects')]
    public function test_approval_creates_or_reuses_a_catalogue_course_transactionally(?int $existing, string $type): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('transactionLevel')->andReturn(1);
        $requests = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('custom_subject_requests')->andReturn($requests);
        $requests->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $requests->shouldReceive('first')->twice()->andReturn((object) ['id' => 90, 'user_id' => 42, 'profile_type' => $type, 'name' => 'New Course', 'status' => 'pending']);
        $requests->shouldReceive('update')->once()->with(Mockery::on(fn ($row) => $row['status'] === 'approved' && $row['subject_id'] === 17))->andReturn(1);
        $users = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->andReturn($users);
        $users->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $users->shouldReceive('first')->once()->andReturn((object) ['id' => 42, 'account_type' => $type === 'job' ? 'learner' : $type]);
        if ($type !== 'job') {
            $profile = Mockery::mock(Builder::class);
            DB::shouldReceive('table')->with($type.'_profiles')->andReturn($profile);
            $profile->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
            $profile->shouldReceive('first')->once()->andReturn((object) ['user_id' => 42]);
            $profile->shouldReceive('exists')->andReturn(false);
            $profile->shouldReceive('update')->once()->andReturn(1);
            $selection = Mockery::mock(Builder::class);
            DB::shouldReceive('table')->with('profile_subjects')->andReturn($selection);
            $selection->shouldReceive('where')->andReturnSelf();
            $selection->shouldReceive('pluck')->with('subject_id')->once()->andReturn(collect([7]));
            $selection->shouldReceive('insertOrIgnore')->once()->with(['profile_type' => $type, 'profile_id' => 42, 'subject_id' => 17])->andReturn(1);
            $publisher = Mockery::mock();
            $publisher->shouldReceive('publish')->once()->with(42);
            $this->app->instance($type === 'teacher' ? TeacherPublicPages::class : InstitutePublicPages::class, $publisher);
        }
        $domain = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('subject_domains')->andReturn($domain);
        $domain->shouldReceive('insertOrIgnore')->once()->andReturn(0);
        $domain->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $domain->shouldReceive('first')->once()->andReturn((object) ['id' => 5]);
        $subjects = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('subjects')->andReturn($subjects);
        $subjects->shouldReceive('where', 'whereRaw')->andReturnSelf();
        $subjects->shouldReceive('value')->with('id')->once()->andReturn($existing);
        if ($existing === null) {
            $subjects->shouldReceive('insertGetId')->once()->with(['domain_id' => 5, 'name' => 'New Course', 'is_bsl' => false])->andReturn(17);
        } else {
            $subjects->shouldReceive('insertGetId')->never();
        }
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->andReturn($history);
        $history->shouldReceive('insertGetId')->times($existing === null ? 2 : 1)->andReturn(1);
        $this->assertTrue(app(CustomSubjectApproval::class)->review(90, 'approved'));
    }

    public function test_rejection_records_history_without_creating_or_attaching_a_subject(): void
    {
        DB::shouldReceive('transaction')->once()->andReturnUsing(fn ($callback) => $callback());
        DB::shouldReceive('transactionLevel')->andReturn(1);
        $requests = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('custom_subject_requests')->andReturn($requests);
        $requests->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $requests->shouldReceive('first')->twice()->andReturn((object) ['id' => 90, 'user_id' => 42, 'status' => 'pending']);
        $users = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('users')->once()->andReturn($users);
        $users->shouldReceive('where', 'lockForUpdate')->andReturnSelf();
        $users->shouldReceive('first')->once()->andReturn((object) ['id' => 42]);
        $requests->shouldReceive('update')->once()->with(Mockery::on(fn ($row) => $row['status'] === 'rejected' && $row['subject_id'] === null))->andReturn(1);
        $history = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('business_activity')->andReturn($history);
        $history->shouldReceive('insertGetId')->once()->with(Mockery::on(fn ($row) => $row['event'] === 'course_request.reviewed'
            && json_decode($row['after_data'], true) === ['status' => 'rejected', 'subject_id' => null]))->andReturn(1);
        $this->assertTrue(app(CustomSubjectApproval::class)->review(90, 'rejected'));
    }

    public function test_reports_filter_requests_and_export_only_explicit_columns(): void
    {
        config(['database.default' => 'sqlite', 'database.connections.sqlite' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        $filters = app(ReportFilters::class)->validate(Request::create('/', 'GET', ['status' => 'pending', 'q' => 'Abacus']), 'custom-course-requests');
        [$query, $columns] = app(ReportQuery::class)->build(app(ReportRegistry::class)->get('custom-course-requests'), $filters);
        $this->assertStringContainsString('"r"."status" = ?', $query->toSql());
        $this->assertContains('pending', $query->getBindings());
        $this->assertContains('%Abacus%', $query->getBindings());
        $this->assertNotContains('name_hash', $columns);
        $stream = Mockery::mock(Builder::class);
        $stream->shouldReceive('lazy')->with(500)->once()->andReturn(LazyCollection::make([(object) ['name' => 'Abacus', 'status' => 'pending']]));
        $response = app(CsvExporter::class)->download($stream, $columns, 'custom-courses');
        ob_start();
        try {
            $response->sendContent();
            $csv = ob_get_contents();
        } finally {
            ob_end_clean();
        }
        $this->assertStringContainsString('Abacus', $csv);
        $this->assertStringNotContainsString('name_hash', $csv);
    }

    public function test_admin_queue_controls_are_rendered_and_all_reads_and_actions_require_admin(): void
    {
        config(['app.url' => 'http://localhost']);
        $this->app['url']->forceRootUrl('http://localhost');
        foreach (['/admin/moderation/course-requests', '/admin/reports/custom-course-requests', '/admin/reports/custom-course-requests?export=1'] as $url) {
            $this->get($url)->assertRedirect(route('admin.login'));
        }
        $this->patch('/admin/moderation/course-requests/90', ['status' => 'approved'])->assertRedirect(route('admin.login'));
        $requests = new LengthAwarePaginator(collect([(object) [
            'id' => 90, 'name' => '<script>Abacus</script>', 'full_name' => 'Teacher', 'profile_type' => 'teacher',
            'status' => 'pending', 'user_id' => 42, 'created_at' => '2026-11-18 10:00:00',
        ]]), 1, 25);
        $html = view('admin.moderation.course-requests', ['requests' => $requests, 'status' => 'pending', 'domains' => collect([(object) ['id' => 3, 'name' => 'Mathematics']]), 'errors' => new ViewErrorBag])->render();
        $this->assertStringContainsString('name="domain_id" required', $html);
        $this->assertStringContainsString('<option value="3">Mathematics</option>', $html);
        $this->assertStringContainsString('>Approve</button>', $html);
        $this->assertStringContainsString('>Reject</button>', $html);
        $this->assertStringContainsString('&lt;script&gt;Abacus&lt;/script&gt;', $html);
    }
}
