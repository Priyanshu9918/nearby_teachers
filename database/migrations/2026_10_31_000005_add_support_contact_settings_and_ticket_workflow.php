<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_contact_settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('whatsapp_number', 32)->nullable();
            $table->string('email', 254)->nullable();
            $table->string('linkedin_url', 2048)->nullable();
            $table->string('linkedin_name', 160)->nullable();
            $table->string('linkedin_title', 160)->nullable();
            $table->dateTime('created_at', 6)->useCurrent();
            $table->dateTime('updated_at', 6)->useCurrent();
        });

        DB::table('support_contact_settings')->insert([
            'id' => 1,
            'whatsapp_number' => null,
            'email' => config('services.nearby_teacher.support_email'),
            'linkedin_url' => null,
            'linkedin_name' => null,
            'linkedin_title' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->string('status', 24)->default('pending')->change();
        });
        DB::table('support_tickets')->where('status', 'open')->update(['status' => 'pending']);
    }

    public function down(): void
    {
        DB::table('support_tickets')->where('status', 'pending')->update(['status' => 'open']);
        DB::table('support_tickets')->where('status', 'rejected')->update(['status' => 'resolved']);
        Schema::table('support_tickets', function (Blueprint $table): void {
            $table->enum('status', ['open', 'in_progress', 'resolved'])->default('open')->change();
        });
        Schema::dropIfExists('support_contact_settings');
    }
};
