<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('seed_example')->default(false);
            $table->string('preview_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropUnique(['preview_token']);
            $table->dropColumn(['seed_example', 'preview_token']);
        });
    }
};
