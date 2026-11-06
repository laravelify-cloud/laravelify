<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The version of a website's own toolchain when it is not PHP or Node.js: Python 3.12,
     * Go 1.24, Bun 1.2, Hugo 0.145.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('runtime_version', 20)->nullable()->after('node_version');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('runtime_version');
        });
    }
};
