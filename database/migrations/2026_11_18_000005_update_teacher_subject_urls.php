<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Superseded by 000006: preserve the original SEO URL and add a separate share URL.
    }

    public function down(): void
    {
        // Retain shared URL ownership and append-only publication history.
    }
};
