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
            // Encrypted with APP_KEY, so the admin can show it after the authenticator code.
            // nginx still checks basic_auth_hash.
            $table->text('basic_auth_password')->nullable()->after('basic_auth_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('basic_auth_password');
        });
    }
};
