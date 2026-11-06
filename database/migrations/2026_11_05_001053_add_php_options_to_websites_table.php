<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A website's own PHP options (memory_limit, upload size…), written into its PHP-FPM pool.
     * Empty: PHP's defaults.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->json('php_options')->nullable()->after('php_version');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('php_options');
        });
    }
};
