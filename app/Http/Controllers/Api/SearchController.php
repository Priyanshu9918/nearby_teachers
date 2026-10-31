<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Reporting\SearchAnalyticsRecorder;
use App\Services\Presence\UserPresence;
use App\Support\GeoBounds;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SearchController extends Controller
{
    private const MIN_VOTES = 5;

    private const PRIOR_RATING = 4.0;

    public function dashboard(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radiusKm' => ['sometimes', 'numeric', 'gt:0', 'max:500'],
        ]);
        $latitude = (float) $data['lat'];
        $longitude = (float) $data['lng'] === -180.0 ? 180.0 : (float) $data['lng'];
        $radiusKm = (float) ($data['radiusKm'] ?? 7);
        [$bounds, $bindings] = GeoBounds::sql($latitude, $longitude, $radiusKm * 1000, 'p');
        $results = collect();
        $counts = ['teacher' => 0, 'institute' => 0];

        foreach (['teacher', 'institute'] as $type) {
            $specificFields = $type === 'teacher'
                ? ['p.bio', 'p.rate_per_hour', 'p.linkedin_url', 'p.primary_subject_id']
                : ['p.description', 'p.starting_rate', 'p.contact_person', 'p.instructors_text',
                    'p.website_url', 'p.institute_type', 'p.is_bsl_official', 'p.bsl_official_since'];
            $profiles = DB::table($type.'_profiles as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('u.account_type', $type)
                ->whereNull('u.suspended_at')
                ->whereNotNull('u.phone_verified_at')
                ->whereNotNull('u.profile_completed_at')
                ->whereRaw($bounds, $bindings)
                ->whereNotNull('p.longitude')
                ->select([
                    'p.id', 'p.user_id', 'u.full_name', 'u.referral_points', 'p.currency', 'p.lesson_modes',
                    'p.latitude', 'p.longitude', 'p.address_text', 'p.city', 'p.state',
                    'p.country', 'p.country_code', 'p.slug', 'p.is_verified', 'p.verified_at',
                    'p.trial_days', 'p.rating_avg', 'p.rating_count', 'p.created_at', 'p.updated_at',
                    ...$specificFields,
                ])
                ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
                ->selectRaw('ST_Distance_Sphere(POINT(IF(p.longitude = -180, 180, p.longitude), p.latitude), POINT(?, ?)) AS distance_m', [$longitude, $latitude])
                ->having('distance_m', '<=', $radiusKm * 1000)
                ->get();

            // Load subjects in bulk, rather than querying once per map marker.
            $subjects = $profiles->isEmpty() ? collect() : DB::table('profile_subjects as ps')
                ->join('subjects as s', 's.id', '=', 'ps.subject_id')
                ->where('ps.profile_type', $type)
                ->whereIn('ps.profile_id', $profiles->pluck('user_id'))
                ->select('ps.profile_id', 's.id', 's.name', 's.is_bsl')
                ->orderBy('s.name')->orderBy('s.id')->get()->groupBy('profile_id');

            foreach ($profiles as $profile) {
                $profile->profile_type = $type;
                $profile->latitude = (float) $profile->latitude;
                $profile->longitude = (float) $profile->longitude;
                $profile->distance_km = (float) $profile->distance_m / 1000;
                unset($profile->distance_m);
                $profile->lesson_modes = json_decode($profile->lesson_modes, true, 512, JSON_THROW_ON_ERROR);
                $rate = $type === 'teacher' ? 'rate_per_hour' : 'starting_rate';
                $profile->{$rate} = $profile->{$rate} === null ? null : (float) $profile->{$rate};
                $profile->price = $profile->{$rate};
                $profile->rating_avg = (float) $profile->rating_avg;
                $profile->rating_count = (int) $profile->rating_count;
                $profile->referral_points = (int) $profile->referral_points;
                $profile->is_verified = (bool) $profile->is_verified;
                $profile->trial_days = (int) $profile->trial_days;
                if ($type === 'teacher') {
                    $profile->primary_subject_id = $profile->primary_subject_id === null ? null : (int) $profile->primary_subject_id;
                } else {
                    $profile->is_bsl_official = (bool) $profile->is_bsl_official;
                }
                $profile->subjects = $subjects->get($profile->user_id, collect())->map(static fn (object $subject): array => [
                    'id' => (int) $subject->id,
                    'name' => $subject->name,
                    'is_bsl' => (bool) $subject->is_bsl,
                ])->values()->all();
                $results->push($profile);
            }
            $counts[$type] = $profiles->count();
        }

        app(SearchAnalyticsRecorder::class)->record('dashboard', 'nearby_grid', null, null,
            $results->count(), $latitude, (float) $data['lng'], $radiusKm);

        return $this->providerResponse([
            'center' => ['latitude' => $latitude, 'longitude' => (float) $data['lng']],
            'radiusKm' => $radiusKm,
            'teacherCount' => $counts['teacher'],
            'instituteCount' => $counts['institute'],
            'totalCount' => $results->count(),
            'results' => $results->sortBy([
                ['referral_points', 'desc'], ['distance_km', 'asc'], ['profile_type', 'asc'], ['user_id', 'asc'],
            ])->values(),
        ]);
    }

    public function nearby(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radiusKm' => ['sometimes', 'numeric', 'gt:0', 'max:500'],
            'subjectId' => ['sometimes', 'integer', 'exists:subjects,id'],
            'q' => ['sometimes', 'string', 'max:200'],
            'freeOnly' => ['sometimes', 'in:true,false'],
        ]);
        $started = hrtime(true);
        $radiusMeters = ((float) ($data['radiusKm'] ?? 7)) * 1000;
        $longitude = (float) $data['lng'] === -180.0 ? 180.0 : (float) $data['lng'];
        [$bounds, $boundsBindings] = GeoBounds::sql((float) $data['lat'], $longitude, $radiusMeters, 'p');
        $branches = [];
        foreach ([['teacher', 'teacher_profiles', 'rate_per_hour'], ['institute', 'institute_profiles', 'starting_rate']] as [$type, $table, $price]) {
            $query = DB::table($table.' as p')
                ->join('users as u', 'u.id', '=', 'p.user_id')
                ->selectRaw('? AS profile_type', [$type])
                ->addSelect('p.id as profile_id')
                ->addSelect('u.referral_points')
                ->selectRaw('ST_Distance_Sphere(POINT(IF(p.longitude = -180, 180, p.longitude), p.latitude), POINT(?, ?)) AS distance_m', [$longitude, (float) $data['lat']])
                ->whereRaw($bounds, $boundsBindings)
                ->whereNotNull('p.longitude');

            if (isset($data['subjectId'])) {
                $query->whereExists(function ($subjects) use ($type, $data): void {
                    $subjects->selectRaw('1')->from('profile_subjects as ps')
                        ->whereColumn('ps.profile_id', 'p.user_id')->where('ps.profile_type', $type)
                        ->where('ps.subject_id', $data['subjectId']);
                });
            }
            if (isset($data['q']) && $data['q'] !== '') {
                $query->whereExists(function ($names) use ($data): void {
                    $names->selectRaw('1')->from('users as name_match')
                        ->whereColumn('name_match.id', 'p.user_id')->where('name_match.full_name', 'like', '%'.$data['q'].'%');
                });
            }
            if (($data['freeOnly'] ?? 'false') === 'true') {
                $query->where('p.'.$price, 0);
            }
            // Each type contributes its highest-ranked 50 rows to the combined result set.
            $branches[] = $query->having('distance_m', '<=', $radiusMeters)
                ->orderByDesc('referral_points')->orderBy('distance_m')->orderBy('p.id')->limit(50);
        }
        $nearest = DB::query()->fromSub($branches[0]->unionAll($branches[1]), 'candidates')
            ->orderByDesc('referral_points')->orderBy('distance_m')->orderBy('profile_type')->orderBy('profile_id')->limit(50);
        $rows = DB::query()->fromSub($nearest, 'nearest')
            ->leftJoin('teacher_profiles as teacher', function ($join): void {
                $join->on('teacher.id', '=', 'nearest.profile_id')->where('nearest.profile_type', 'teacher');
            })
            ->leftJoin('institute_profiles as institute', function ($join): void {
                $join->on('institute.id', '=', 'nearest.profile_id')->where('nearest.profile_type', 'institute');
            })
            ->join('users as u', fn ($join) => $join->whereRaw('u.id = COALESCE(teacher.user_id, institute.user_id)'))
            ->selectRaw('STRAIGHT_JOIN nearest.profile_type, COALESCE(teacher.user_id, institute.user_id) AS user_id,
                COALESCE(teacher.rate_per_hour, institute.starting_rate) AS price,
                COALESCE(teacher.rating_avg, institute.rating_avg) AS rating_avg,
                COALESCE(teacher.rating_count, institute.rating_count) AS rating_count,
                COALESCE(teacher.is_verified, institute.is_verified) AS is_verified,
                COALESCE(teacher.trial_days, institute.trial_days) AS trial_days,
                nearest.referral_points, nearest.distance_m, u.full_name')
            ->selectRaw("CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url")
            ->orderByDesc('nearest.referral_points')->orderBy('nearest.distance_m')->orderBy('nearest.profile_type')->orderBy('nearest.profile_id')
            ->get()->map(static function (object $result): object {
                $result->referral_points = (int) $result->referral_points;
                $result->rating_avg = (float) $result->rating_avg;
                $result->rating_count = (int) $result->rating_count;
                $result->distance_km = $result->distance_m / 1000;
                unset($result->distance_m);

                return $result;
            });

        app(SearchAnalyticsRecorder::class)->record('nearby', 'nearby_grid', null,
            isset($data['subjectId']) ? (int) $data['subjectId'] : null, $rows->count(),
            (float) $data['lat'], (float) $data['lng'], (float) ($data['radiusKm'] ?? 7),
            ($data['freeOnly'] ?? 'false') === 'true');

        return $this->providerResponse(['results' => $rows])
            ->header('Server-Timing', 'search;dur='.round((hrtime(true) - $started) / 1000000, 2));
    }

    public function counts(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'radiusKm' => ['sometimes', 'numeric', 'gt:0', 'max:500'],
        ]);
        $radiusMeters = ((float) ($data['radiusKm'] ?? 7)) * 1000;
        $data['lng'] = (float) $data['lng'] === -180.0 ? 180.0 : (float) $data['lng'];
        [$bounds, $boundsBindings] = GeoBounds::sql((float) $data['lat'], (float) $data['lng'], $radiusMeters);
        $distance = 'ST_Distance_Sphere(POINT(IF(longitude = -180, 180, longitude), latitude), POINT(?, ?))';
        $sql = sprintf(
            'SELECT (SELECT count(*) FROM teacher_profiles WHERE %2$s AND longitude IS NOT NULL AND %1$s <= ?) AS teacher_count, (SELECT count(*) FROM institute_profiles WHERE %2$s AND longitude IS NOT NULL AND %1$s <= ?) AS institute_count',
            $distance, $bounds
        );

        return response()->json(DB::selectOne($sql, [
            ...$boundsBindings, (float) $data['lng'], (float) $data['lat'], $radiusMeters,
            ...$boundsBindings, (float) $data['lng'], (float) $data['lat'], $radiusMeters,
        ]));
    }

    public function otherCity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'city' => ['required', 'string', 'max:160'],
            'subjectId' => ['sometimes', 'integer', 'exists:subjects,id'],
            'q' => ['sometimes', 'string', 'max:200'],
        ]);
        $sql = <<<'SQL'
            WITH t AS (
              SELECT 'teacher' AS profile_type, user_id, city, country, lesson_modes,
                     rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days,
                     referral_user.referral_points
              FROM teacher_profiles JOIN users referral_user ON referral_user.id = teacher_profiles.user_id
              UNION ALL
              SELECT 'institute' AS profile_type, user_id, city, country, lesson_modes,
                     starting_rate AS price, rating_avg, rating_count, is_verified, trial_days,
                     referral_user.referral_points
              FROM institute_profiles JOIN users referral_user ON referral_user.id = institute_profiles.user_id
            )
            SELECT t.profile_type, t.user_id, u.full_name,
                   CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url,
                   t.city, t.country, t.price, t.rating_avg, t.rating_count, t.is_verified, t.trial_days, t.referral_points
            FROM t
            JOIN users u ON u.id = t.user_id
            WHERE JSON_CONTAINS(t.lesson_modes, JSON_QUOTE('online')) = 1
              AND (t.city LIKE ? OR t.country LIKE ?)
            SQL;
        $bindings = ['%'.$data['city'].'%', '%'.$data['city'].'%'];
        if (isset($data['subjectId'])) {
            $sql .= ' AND EXISTS (SELECT 1 FROM profile_subjects ps WHERE ps.profile_type = t.profile_type AND ps.profile_id = t.user_id AND ps.subject_id = ?)';
            $bindings[] = $data['subjectId'];
        }
        if (isset($data['q']) && $data['q'] !== '') {
            $sql .= ' AND u.full_name LIKE ?';
            $bindings[] = '%'.$data['q'].'%';
        }
        $sql .= ' ORDER BY t.referral_points DESC, '.$this->weightedRating('t.rating_avg', 't.rating_count').' DESC, t.user_id ASC LIMIT 50';
        $results = DB::select($sql, $bindings);
        app(SearchAnalyticsRecorder::class)->record('other_city', 'city_or_country', $data['city'],
            isset($data['subjectId']) ? (int) $data['subjectId'] : null, count($results));

        return $this->providerResponse(['results' => $results]);
    }

    public function bySubject(Request $request, int $subjectId): JsonResponse
    {
        $data = $request->validate([
            'tier' => ['required', 'in:nearby,city,state,country,other-countries'],
            'lat' => ['required_if:tier,nearby', 'numeric', 'between:-90,90'],
            'lng' => ['required_if:tier,nearby', 'numeric', 'between:-180,180'],
            'radiusKm' => ['sometimes', 'numeric', 'gt:0', 'max:500'],
            'city' => ['required_if:tier,city', 'string', 'max:160'],
            'state' => ['required_if:tier,state', 'string', 'max:160'],
            'country' => ['sometimes', 'required_if:tier,country', 'string', 'max:160'],
        ]);
        $base = <<<'SQL'
            WITH t AS (
              SELECT 'teacher' AS profile_type, user_id, latitude, longitude, city, state, country,
                     rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days,
                     referral_user.referral_points
              FROM teacher_profiles JOIN users referral_user ON referral_user.id = teacher_profiles.user_id
              UNION ALL
              SELECT 'institute' AS profile_type, user_id, latitude, longitude, city, state, country,
                     starting_rate AS price, rating_avg, rating_count, is_verified, trial_days,
                     referral_user.referral_points
              FROM institute_profiles JOIN users referral_user ON referral_user.id = institute_profiles.user_id
            ), matching AS (
              SELECT t.* FROM t
              WHERE EXISTS (
                SELECT 1 FROM profile_subjects ps
                WHERE ps.profile_type = t.profile_type AND ps.profile_id = t.user_id AND ps.subject_id = ?
              )
            )
            SQL;
        $tier = $data['tier'];
        if ($tier === 'nearby') {
            $radiusMeters = ((float) ($data['radiusKm'] ?? 7)) * 1000;
            $data['lng'] = (float) $data['lng'] === -180.0 ? 180.0 : (float) $data['lng'];
            [$bounds, $boundsBindings] = GeoBounds::sql((float) $data['lat'], (float) $data['lng'], $radiusMeters);
            $base = str_replace(
                ['FROM teacher_profiles JOIN users referral_user ON referral_user.id = teacher_profiles.user_id',
                    'FROM institute_profiles JOIN users referral_user ON referral_user.id = institute_profiles.user_id'],
                ['FROM teacher_profiles JOIN users referral_user ON referral_user.id = teacher_profiles.user_id WHERE '.$bounds,
                    'FROM institute_profiles JOIN users referral_user ON referral_user.id = institute_profiles.user_id WHERE '.$bounds],
                $base,
            );
            $point = 'POINT(?, ?)';
            $sql = $base.sprintf(<<<'SQL'
                SELECT m.profile_type, m.user_id, u.full_name,
                       CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url,
                       m.price, m.rating_avg, m.rating_count, m.is_verified, m.trial_days, m.referral_points,
                       ST_Distance_Sphere(POINT(IF(m.longitude = -180, 180, m.longitude), m.latitude), %s) AS distance_m
                FROM matching m JOIN users u ON u.id = m.user_id
                WHERE m.latitude IS NOT NULL AND m.longitude IS NOT NULL
                  AND ST_Distance_Sphere(POINT(IF(m.longitude = -180, 180, m.longitude), m.latitude), %s) <= ?
                ORDER BY m.referral_points DESC, distance_m ASC, m.user_id ASC LIMIT 50
                SQL, $point, $point);
            $bindings = [
                ...$boundsBindings, ...$boundsBindings, $subjectId,
                (float) $data['lng'], (float) $data['lat'],
                (float) $data['lng'], (float) $data['lat'], $radiusMeters,
            ];
            $countSql = $base.' SELECT count(*) AS total FROM matching m WHERE m.latitude IS NOT NULL AND m.longitude IS NOT NULL AND ST_Distance_Sphere(POINT(IF(m.longitude = -180, 180, m.longitude), m.latitude), POINT(?, ?)) <= ?';
            $countBindings = [...$boundsBindings, ...$boundsBindings, $subjectId, (float) $data['lng'], (float) $data['lat'], $radiusMeters];
            $results = array_map(static function (object $result): object {
                $result->distance_km = $result->distance_m / 1000;
                unset($result->distance_m);

                return $result;
            }, DB::select($sql, $bindings));
            $totalCount = (int) DB::selectOne($countSql, $countBindings)->total;
            app(SearchAnalyticsRecorder::class)->record('subject', 'nearby_grid', null, $subjectId,
                $totalCount, (float) $data['lat'], (float) $data['lng'], (float) ($data['radiusKm'] ?? 7));

            return $this->providerResponse([
                'tier' => $tier,
                'totalCount' => $totalCount,
                'results' => $results,
            ]);
        }

        if (in_array($tier, ['city', 'state', 'country'], true)) {
            $column = ['city' => 'city', 'state' => 'state', 'country' => 'country'][$tier];
            $value = $data[$column];
            $sql = $base." SELECT m.profile_type, m.user_id, u.full_name, CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url, m.price, m.rating_avg, m.rating_count, m.is_verified, m.trial_days, m.referral_points, m.city, m.state, m.country FROM matching m JOIN users u ON u.id = m.user_id WHERE lower(m.$column) = lower(?) ORDER BY m.referral_points DESC, ".$this->weightedRating('m.rating_avg', 'm.rating_count').' DESC, m.user_id ASC LIMIT 50';
            $countSql = $base." SELECT count(*) AS total FROM matching m WHERE lower(m.$column) = lower(?)";

            $totalCount = (int) DB::selectOne($countSql, [$subjectId, $value])->total;
            app(SearchAnalyticsRecorder::class)->record('subject', $tier, $value, $subjectId, $totalCount);

            return $this->providerResponse([
                'tier' => $tier,
                'totalCount' => $totalCount,
                'results' => DB::select($sql, [$subjectId, $value]),
            ]);
        }

        if ($tier === 'other-countries') {
            if (isset($data['country'])) {
                $sql = $base.' SELECT m.profile_type, m.user_id, u.full_name, CASE WHEN u.avatar_status = \'approved\' THEN u.avatar_url ELSE NULL END AS avatar_url, m.price, m.rating_avg, m.rating_count, m.is_verified, m.trial_days, m.referral_points, m.city, m.country FROM matching m JOIN users u ON u.id = m.user_id WHERE lower(m.country) = lower(?) ORDER BY m.referral_points DESC, '.$this->weightedRating('m.rating_avg', 'm.rating_count').' DESC, m.user_id ASC LIMIT 50';
                $countSql = $base.' SELECT count(*) AS total FROM matching m WHERE lower(m.country) = lower(?)';

                $totalCount = (int) DB::selectOne($countSql, [$subjectId, $data['country']])->total;
                app(SearchAnalyticsRecorder::class)->record('subject', 'country', $data['country'], $subjectId, $totalCount);

                return $this->providerResponse([
                    'tier' => $tier,
                    'country' => $data['country'],
                    'totalCount' => $totalCount,
                    'results' => DB::select($sql, [$subjectId, $data['country']]),
                ]);
            }
            $countries = DB::select($base.' SELECT m.country, count(*) AS trainer_count FROM matching m WHERE m.country IS NOT NULL GROUP BY m.country ORDER BY trainer_count DESC', [$subjectId]);
            app(SearchAnalyticsRecorder::class)->record('subject', 'other_countries', null, $subjectId, count($countries));

            return response()->json(['tier' => $tier, 'countries' => $countries]);
        }

        return response()->json(['error' => 'Invalid search tier'], 400);
    }

    private function providerResponse(array $data): JsonResponse
    {
        $data['results'] = app(UserPresence::class)->decorate($data['results']);
        $teacherIds = [];
        foreach ($data['results'] as $row) {
            if (property_exists($row, 'referral_points')) {
                $row->referral_points = (int) $row->referral_points;
            }
            if (property_exists($row, 'rating_avg') && $row->rating_avg !== null) {
                $row->rating_avg = (float) $row->rating_avg;
            }
            if (property_exists($row, 'rating_count')) {
                $row->rating_count = (int) $row->rating_count;
            }
            if (($row->profile_type ?? null) === 'teacher' && isset($row->user_id)) {
                $teacherIds[] = $row->user_id;
            }
        }
        if ($teacherIds !== []) {
            $names = DB::table('teacher_profiles')->whereIn('user_id', array_unique($teacherIds))
                ->pluck('listing_name', 'user_id');
            foreach ($data['results'] as $row) {
                if (($row->profile_type ?? null) === 'teacher') {
                    $row->listing_name = $names[$row->user_id] ?? null;
                    $row->full_name = $row->listing_name ?: $row->full_name;
                }
            }
        }

        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    private function weightedRating(string $averageColumn, string $countColumn): string
    {
        return "($countColumn * COALESCE($averageColumn, 0) + ".self::MIN_VOTES.' * '.self::PRIOR_RATING.") / ($countColumn + ".self::MIN_VOTES.')';
    }
}
