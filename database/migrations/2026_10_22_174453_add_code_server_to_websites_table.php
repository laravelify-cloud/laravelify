<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VS Code (code-server) for a website: the local port it listens on, and the random path
     * nginx serves it at (/code/<token>/).
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->unsignedInteger('code_server_port')->nullable();
            $table->string('code_server_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropUnique(['code_server_token']);
            $table->dropColumn(['code_server_port', 'code_server_token']);
        });
    }
};
