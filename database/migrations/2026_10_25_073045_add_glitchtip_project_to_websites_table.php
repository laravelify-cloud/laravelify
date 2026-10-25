<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            // The website's project in GlitchTip (error tracking), once it is connected.
            $table->string('glitchtip_project')->nullable();
            $table->unsignedBigInteger('glitchtip_project_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['glitchtip_project', 'glitchtip_project_id']);
        });
    }
};
