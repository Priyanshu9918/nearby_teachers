<?php

use App\Services\RegistrationPoints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Serialize deployment with registration traffic while changing this default.
        $lastExistingId = (int) DB::table('users')->max('id');
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('referral_points')->default(100)->change();
        });
        DB::table('users')->where('id', '<=', $lastExistingId)->whereNotExists(function ($query): void {
            $query->selectRaw('1')->from('business_activity')
                ->where('event', 'user.registration_points_granted')->where('entity_type', 'user')
                ->whereColumn('entity_id', 'users.id');
        })->orderBy('id')->chunkById(500, function ($users): void {
            foreach ($users as $user) {
                app(RegistrationPoints::class)->awardExisting((int) $user->id);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('referral_points')->default(0)->change();
        });
        // Granted points and their append-only history are retained on rollback.
    }
};
