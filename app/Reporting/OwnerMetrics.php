<?php

namespace App\Reporting;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class OwnerMetrics
{
    /** @return array<string, array{label: string, value: int|float|null, kind: string}> */
    public function summarize(Carbon $from, Carbon $through): array
    {
        $start = $from->copy()->startOfDay();
        $end = $through->copy()->addDay()->startOfDay();
        $acceptedStatuses = DB::table('business_activity')
            ->where('event', 'booking.status_changed')->whereBetween('created_at', [$start, $end])
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.status')) IN ('accepted', 'declined')")
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(after_data, '$.status')) as booking_status")
            ->pluck('booking_status');
        $accepted = $acceptedStatuses->filter(static fn ($status) => $status === 'accepted')->count();
        $answered = $acceptedStatuses->count();
        $averageRating = DB::table('reviews')->whereBetween('created_at', [$start, $end])->avg('rating');

        return [
            'new_learners' => $this->metric('New learners', $this->count('users', 'created_at', $start, $end, fn (Builder $q) => $q->where('account_type', 'learner'))),
            'new_teachers' => $this->metric('New teachers', $this->count('users', 'created_at', $start, $end, fn (Builder $q) => $q->where('account_type', 'teacher'))),
            'new_institutes' => $this->metric('New institutes', $this->count('users', 'created_at', $start, $end, fn (Builder $q) => $q->where('account_type', 'institute'))),
            'teacher_pages' => $this->metric('Teacher pages published', $this->count('business_activity', 'created_at', $start, $end, fn (Builder $q) => $q->where('event', 'teacher.pages_published'))),
            'institute_pages' => $this->metric('Institute pages published', $this->count('business_activity', 'created_at', $start, $end, fn (Builder $q) => $q->where('event', 'institute.profile_published'))),
            'lesson_requests' => $this->metric('Lesson requests', $this->count('bookings', 'created_at', $start, $end)),
            'lessons_accepted' => $this->metric('Lessons accepted', $accepted),
            'accepted_rate' => $this->metric('Accepted % (of answered)', $answered === 0 ? null : round($accepted * 100 / $answered, 1), 'percent'),
            'messages_sent' => $this->metric('Messages sent', $this->count('messages', 'created_at', $start, $end)),
            'searches' => $this->metric('Searches', $this->count('search_analytics', 'created_at', $start, $end)),
            'empty_searches' => $this->metric('Searches that found nobody', $this->count('search_analytics', 'created_at', $start, $end, fn (Builder $q) => $q->where('result_count', 0))),
            'page_views' => $this->metric('Website pages viewed', $this->count('page_views', 'created_at', $start, $end, fn (Builder $q) => $q->whereIn('page_type', ['teacher', 'institute', 'city_index', 'city_feed', 'city_directory']))),
            'downloads' => $this->metric('App download clicks', $this->count('page_views', 'created_at', $start, $end, fn (Builder $q) => $q->where('page_type', 'download'))),
            'referrals' => $this->metric('Referrals joined', $this->count('referrals', 'created_at', $start, $end)),
            'reviews' => $this->metric('Reviews', $this->count('reviews', 'created_at', $start, $end)),
            'average_rating' => $this->metric('Average rating', $averageRating === null ? null : round((float) $averageRating, 1), 'rating'),
            'job_applications' => $this->metric('Job applications', $this->count('teaching_job_applications', 'created_at', $start, $end)),
            'franchise_enquiries' => $this->metric('Franchise enquiries', $this->count('franchise_enquiries', 'created_at', $start, $end)),
            'contact_requests' => $this->metric('Contact requests', $this->count('phone_reveals', 'revealed_at', $start, $end)),
            'support_tickets' => $this->metric('Call-back requests (support tickets)', $this->count('support_tickets', 'created_at', $start, $end)),
            'safety_reports' => $this->metric('Safety reports', $this->count('reports', 'created_at', $start, $end)),
        ];
    }

    public function queues(): array
    {
        $jobs = DB::table('teaching_job_applications')->where('status', 'submitted')->count();
        $franchises = DB::table('franchise_enquiries')->where('status', 'submitted')->count();
        $support = DB::table('support_tickets')->whereIn('status', ['open', 'in_progress'])->count();

        return [
            'safety_reports' => DB::table('reports')->where('status', 'open')->count(),
            'photos' => DB::table('users')->where('avatar_status', 'pending')->count(),
            'possible_duplicates' => $this->possibleDuplicateCount(),
            'job_applications' => $jobs,
            'franchise_enquiries' => $franchises,
            'support_tickets' => $support,
            'new_leads' => $jobs + $franchises + $support,
        ];
    }

    public function snapshot(): array
    {
        $districts = DB::table('teacher_profiles')->whereNotNull('district')->where('district', '!=', '')->distinct()->pluck('district')
            ->merge(DB::table('institute_profiles')->whereNotNull('district')->where('district', '!=', '')->distinct()->pluck('district'))
            ->map(fn ($district) => mb_strtolower(trim($district), 'UTF-8'))->unique()->count();

        return [
            'learners' => DB::table('users')->where('account_type', 'learner')->count(),
            'teacher_pages' => $this->publishedPageCount('teacher_profiles'),
            'institute_pages' => $this->publishedPageCount('institute_profiles'),
            'districts' => $districts,
            'lessons_accepted' => DB::table('bookings')->whereIn('status', ['accepted', 'completed'])->count(),
        ];
    }

    private function publishedPageCount(string $profileTable): int
    {
        return DB::table($profileTable.' as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.is_profile_complete', true)->whereNotNull('p.public_page_slug')
            ->whereNotNull('p.seo_country')->whereNotNull('u.phone_verified_at')
            ->whereNotNull('u.profile_completed_at')->whereNull('u.suspended_at')->count();
    }

    private function possibleDuplicateCount(): int
    {
        return DB::table('teacher_profiles as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereNotNull('p.city')->where('p.city', '!=', '')
            ->whereExists(function (Builder $query): void {
                $query->selectRaw('1')->from('teacher_profiles as other_profile')
                    ->join('users as other_user', 'other_user.id', '=', 'other_profile.user_id')
                    ->whereColumn('other_user.id', '!=', 'u.id')
                    ->whereRaw('LOWER(TRIM(other_user.full_name)) = LOWER(TRIM(u.full_name))')
                    ->whereRaw('LOWER(TRIM(other_profile.city)) = LOWER(TRIM(p.city))')
                    ->whereRaw("LOWER(TRIM(COALESCE(other_profile.country, ''))) = LOWER(TRIM(COALESCE(p.country, '')))");
            })->distinct()->count('u.id');
    }

    private function count(string $table, string $timestamp, Carbon $start, Carbon $end, ?callable $filter = null): int
    {
        $query = DB::table($table)->where($timestamp, '>=', $start)->where($timestamp, '<', $end);
        if ($filter !== null) {
            $filter($query);
        }

        return $query->count();
    }

    private function metric(string $label, int|float|null $value, string $kind = 'count'): array
    {
        return compact('label', 'value', 'kind');
    }
}
