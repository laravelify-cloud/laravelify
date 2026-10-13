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
            // Empty means "use the default for this type" (see App\Support\SiteTypes).
            $table->string('install_command', 500)->nullable()->after('node_version');
            $table->string('build_command', 500)->nullable()->after('install_command');
            $table->string('start_command', 500)->nullable()->after('build_command');
            // Static sites: the folder nginx serves (empty = detect it after the build).
            // PHP sites: the web root inside the repository (empty = public).
            $table->string('public_dir', 190)->nullable()->after('start_command');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['install_command', 'build_command', 'start_command', 'public_dir']);
        });
    }
};
