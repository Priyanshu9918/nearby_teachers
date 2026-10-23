<?php

// Database microbenchmark only. Never run against the application's live database.
use App\Http\Controllers\Api\SearchController;
use App\Support\GeoBounds;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\NumericTestIds;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$database = getenv('API_BENCHMARK_DATABASE');
if (! is_string($database) || ! str_ends_with($database, '_test')) {
    fwrite(STDERR, "Set API_BENCHMARK_DATABASE to an existing disposable MySQL database ending in _test.\n");
    exit(1);
}
$options = getopt('', ['rows:', 'dense']);
$dense = array_key_exists('dense', $options);
$rows = filter_var($options['rows'] ?? 20000, FILTER_VALIDATE_INT);
if ($rows === false || $rows < 1000 || $rows > 500000) {
    fwrite(STDERR, "--rows must be between 1000 and 500000.\n");
    exit(1);
}
config(['database.connections.mysql.url' => null, 'database.connections.mysql.database' => $database]);
DB::purge('mysql');
Artisan::call('migrate', ['--force' => true]);
DB::beginTransaction();
try {
    $learner = NumericTestIds::next();
    $provider = NumericTestIds::next();
    DB::table('users')->insert([
        ['id' => $learner, 'account_type' => 'learner', 'full_name' => 'Benchmark learner'],
        ['id' => $provider, 'account_type' => 'teacher', 'full_name' => 'Benchmark teacher'],
    ]);
    $conversation = NumericTestIds::next();
    DB::table('conversations')->insert([
        'id' => $conversation, 'learner_id' => $learner, 'provider_id' => $provider,
    ]);
    $createdAt = '2026-01-01 00:00:00';
    for ($offset = 0; $offset < $rows; $offset += 500) {
        $users = $profiles = $messages = [];
        for ($i = $offset; $i < min($offset + 500, $rows); $i++) {
            $id = NumericTestIds::next();
            $users[] = ['id' => $id, 'account_type' => 'teacher', 'full_name' => 'Benchmark '.$i];
            $profiles[] = [
                'id' => NumericTestIds::next(), 'user_id' => $id, 'lesson_modes' => '[]',
                'latitude' => ($dense || $i % 100 === 0) ? 28.61 : -70 + (($i * 7919) % 140000) / 1000,
                'longitude' => ($dense || $i % 100 === 0) ? 77.21 : -170 + (($i * 3571) % 340000) / 1000,
            ];
            $messages[] = [
                'id' => NumericTestIds::next(), 'conversation_id' => $conversation,
                'sender_id' => $learner, 'body' => str_repeat('Benchmark message ', 8), 'created_at' => $createdAt,
            ];
        }
        DB::table('users')->insert($users);
        DB::table('teacher_profiles')->insert($profiles);
        DB::table('messages')->insert($messages);
    }

    $measure = static function (callable $callback): array {
        $callback(); // Warm connection and relevant database pages.
        $samples = [];
        for ($i = 0; $i < 5; $i++) {
            $start = hrtime(true);
            $callback();
            $samples[] = (hrtime(true) - $start) / 1000000;
        }
        sort($samples);

        return ['median_ms' => round($samples[2], 3), 'max_ms' => round(max($samples), 3)];
    };
    $beforeChat = $measure(fn () => DB::table('messages')->where('conversation_id', $conversation)
        ->orderBy('created_at')->get());
    $afterChat = $measure(fn () => DB::table('conversations')->where('id', $conversation)
        ->where(fn ($query) => $query->where('learner_id', $learner)->orWhere('provider_id', $learner))->exists());

    [$bounds, $bindings] = GeoBounds::sql(28.61, 77.21, 7000);
    $select = 'SELECT user_id, ST_Distance_Sphere(POINT(longitude, latitude), POINT(77.21, 28.61)) AS distance_m FROM teacher_profiles';
    $ending = ' HAVING distance_m <= 7000 ORDER BY distance_m LIMIT 50';
    $beforeGeo = $measure(fn () => DB::select($select.' WHERE latitude IS NOT NULL AND longitude IS NOT NULL'.$ending));
    $afterGeo = $measure(fn () => DB::select($select.' WHERE '.$bounds.$ending, $bindings));
    $explain = DB::select('EXPLAIN '.$select.' WHERE '.$bounds.$ending, $bindings);
    $original = "WITH t AS (
        SELECT 'teacher' AS profile_type, user_id, latitude, longitude, rate_per_hour AS price, rating_avg, rating_count, is_verified, trial_days FROM teacher_profiles WHERE $bounds
        UNION ALL
        SELECT 'institute' AS profile_type, user_id, latitude, longitude, starting_rate AS price, rating_avg, rating_count, is_verified, trial_days FROM institute_profiles WHERE $bounds
    ), distanced AS (
        SELECT t.profile_type, t.user_id, u.full_name,
        CASE WHEN u.avatar_status = 'approved' THEN u.avatar_url ELSE NULL END AS avatar_url,
        t.price, t.rating_avg, t.rating_count, t.is_verified, t.trial_days,
        ST_Distance_Sphere(POINT(IF(t.longitude = -180, 180, t.longitude), t.latitude), POINT(77.21, 28.61)) AS distance_m
        FROM t JOIN users u ON u.id = t.user_id WHERE t.latitude IS NOT NULL AND t.longitude IS NOT NULL
    ) SELECT * FROM distanced WHERE distance_m <= 7000 ORDER BY distance_m LIMIT 50";
    DB::enableQueryLog();
    (new SearchController)->nearby(Request::create('/search', 'GET', ['lat' => 28.61, 'lng' => 77.21, 'radiusKm' => 7]));
    $queries = DB::getQueryLog();
    $optimized = end($queries);
    DB::disableQueryLog();
    $beforeFull = $measure(fn () => DB::select($original, [...$bindings, ...$bindings]));
    $afterFull = $measure(fn () => DB::select($optimized['query'], $optimized['bindings']));
    $fullPlan = DB::select('EXPLAIN '.$optimized['query'], $optimized['bindings']);

    echo json_encode([
        'database' => $database, 'seeded_profiles' => $rows, 'seeded_messages' => $rows,
        'samples' => 5, 'dense' => $dense, 'full_nearby_before' => $beforeFull, 'full_nearby_after' => $afterFull, 'full_nearby_plan' => $fullPlan, 'chat_membership_before' => $beforeChat, 'chat_membership_after' => $afterChat,
        'nearby_filter_before' => $beforeGeo, 'nearby_filter_after' => $afterGeo,
        'nearby_query_plan' => $explain,
        'scope' => 'Warm serial database microbenchmark; not an HTTP concurrency/capacity test. Seed records are rolled back.',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
} finally {
    DB::rollBack();
}
