<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Basic auth on a website asks for a username and password (password), or for the
     * authenticator code of the panel's admin, remembered in the browser for a day (code).
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('basic_auth_mode', 12)->default('password')->after('basic_auth_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('basic_auth_mode');
        });
    }
};
