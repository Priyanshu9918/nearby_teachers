<?php

namespace Tests\Unit;

use App\Http\Controllers\InternationalSeoController;
use App\Services\TeacherPublicPages;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TeacherSubjectUrlTest extends TestCase
{
    public function test_teacher_url_contains_name_subject_and_city_with_safe_slug_characters(): void
    {
        $this->assertSame('sahib-english-kanpur', TeacherPublicPages::seoSlug('Sahib', 'Kanpur', 'English'));
        $this->assertSame('sahib-english-speaking-kanpur', TeacherPublicPages::seoSlug('Sahib', 'Kanpur', 'English Speaking'));
        $this->assertSame('sahib-ielts-oet-new-delhi', TeacherPublicPages::seoSlug('Sahib', 'New Delhi', 'IELTS / OET'));
        $slug = TeacherPublicPages::seoSlug(str_repeat('a', 250), str_repeat('c', 180), str_repeat('b', 200));
        $this->assertLessThanOrEqual(200, strlen($slug));
        $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $slug);
    }

    public function test_existing_seo_links_and_new_share_links_are_separate(): void
    {
        config(['seo.public_url' => 'https://teachers.example.test']);
        $links = TeacherPublicPages::urls((object) ['public_page_slug' => 'sahib-kanpur', 'subject_profile_slug' => 'sahib-english-kanpur', 'seo_country' => 'in']);
        foreach (['webPageUrl', 'myWebpageUrl'] as $key) {
            $this->assertSame('https://teachers.example.test/teacher/sahib-english-kanpur', $links[$key]);
        }
        $this->assertSame('https://teachers.example.test/teacher/sahib-kanpur', $links['teacherSeoUrl']);
        $this->assertSame($links['teacherSeoUrl'], $links['teacherProfileUrl']);
        $this->assertSame([null], array_values(array_unique(TeacherPublicPages::urls((object) []))));
    }

    public function test_previously_shared_my_webpage_link_redirects_to_full_profile_and_preserves_language(): void
    {
        config(['seo.public_url' => 'http://localhost']);
        $teachers = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('teacher_profiles as p')->once()->andReturn($teachers);
        $teachers->shouldReceive('join', 'where', 'whereNull', 'whereNotNull', 'whereExists', 'select', 'selectRaw', 'selectSub')->andReturnSelf();
        $teachers->shouldReceive('first')->once()->andReturn((object) ['public_page_slug' => 'sahib-kanpur', 'subject_profile_slug' => 'sahib-english-kanpur']);
        $ownership = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('seo_urls')->once()->andReturn($ownership);
        $ownership->shouldReceive('where')->with('path_hash', hash('sha256', '/my-webpage/teacher/sahib-kanpur'))->once()->andReturnSelf();
        $ownership->shouldReceive('where')->with('path', '/my-webpage/teacher/sahib-kanpur')->once()->andReturnSelf();
        $ownership->shouldReceive('where')->with('target_type', 'teacher')->once()->andReturnSelf();
        $ownership->shouldReceive('first')->once()->andReturn((object) ['target_id' => 42]);
        $bookings = Mockery::mock(Builder::class);
        DB::shouldReceive('table')->with('bookings')->once()->andReturn($bookings);
        $bookings->shouldReceive('selectRaw', 'whereColumn', 'where')->andReturnSelf();
        $response = app(InternationalSeoController::class)->myWebpage(Request::create('http://localhost/my-webpage/teacher/sahib-kanpur?lang=hi'), 'teacher', 'sahib-kanpur');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('http://localhost/teacher/sahib-english-kanpur?lang=hi', $response->headers->get('Location'));
    }
}
