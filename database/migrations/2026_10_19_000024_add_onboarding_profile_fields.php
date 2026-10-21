<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                if (! Schema::hasColumn('users', 'password_hash')) {
                    $table->string('password_hash')->nullable()->after('google_id');
                }
                if (! Schema::hasColumn('users', 'terms_accepted_at')) {
                    $table->dateTime('terms_accepted_at', 6)->nullable()->after('safety_disclaimer_acknowledged_at');
                }
                if (! Schema::hasColumn('users', 'profile_completed_at')) {
                    $table->dateTime('profile_completed_at', 6)->nullable()->after('terms_accepted_at');
                }
            });
        }

        if (Schema::hasTable('teacher_profiles') && ! Schema::hasColumn('teacher_profiles', 'primary_subject_id')) {
            Schema::table('teacher_profiles', function (Blueprint $table): void {
                $table->unsignedInteger('primary_subject_id')->nullable()->after('user_id');
                $table->foreign('primary_subject_id', 'teacher_profiles_primary_subject_fk')
                    ->references('id')
                    ->on('subjects')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('institute_profiles') && ! Schema::hasColumn('institute_profiles', 'contact_person')) {
            Schema::table('institute_profiles', function (Blueprint $table): void {
                $table->string('contact_person', 160)->nullable()->after('user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('teacher_profiles') && Schema::hasColumn('teacher_profiles', 'primary_subject_id')) {
            Schema::table('teacher_profiles', function (Blueprint $table): void {
                $table->dropForeign('teacher_profiles_primary_subject_fk');
                $table->dropColumn('primary_subject_id');
            });
        }

        if (Schema::hasTable('institute_profiles') && Schema::hasColumn('institute_profiles', 'contact_person')) {
            Schema::table('institute_profiles', function (Blueprint $table): void {
                $table->dropColumn('contact_person');
            });
        }

        if (Schema::hasTable('users')) {
            Schema::table('users', function (Blueprint $table): void {
                $columns = array_values(array_filter(
                    ['password_hash', 'terms_accepted_at', 'profile_completed_at'],
                    static fn (string $column): bool => Schema::hasColumn('users', $column)
                ));
                if ($columns !== []) {
                    $table->dropColumn($columns);
                }
            });
        }
    }
};
