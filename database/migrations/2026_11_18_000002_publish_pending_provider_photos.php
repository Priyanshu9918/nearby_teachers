<?php

use App\Reporting\BusinessActivityRecorder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereIn('account_type', ['teacher', 'institute'])
            ->where('avatar_status', 'pending')->whereNotNull('avatar_url')->where('avatar_url', '<>', '')
            ->select('id')->chunkById(200, function ($users): void {
                foreach ($users as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        $user = DB::table('users')->where('id', $candidate->id)->lockForUpdate()
                            ->first(['id', 'account_type', 'avatar_status', 'avatar_url']);
                        if ($user === null || ! in_array($user->account_type, ['teacher', 'institute'], true)
                            || $user->avatar_status !== 'pending' || ! filled($user->avatar_url)) {
                            return;
                        }
                        DB::table('users')->where('id', $user->id)->update(['avatar_status' => 'approved', 'updated_at' => now()]);
                        app(BusinessActivityRecorder::class)->record('provider.avatar_auto_published', $user->id, null,
                            before: ['avatar_status' => 'pending'], after: ['avatar_status' => 'approved']);
                    });
                }
            });
    }

    public function down(): void
    {
        // Published photos and append-only history are not reverted by schema rollback.
    }
};
