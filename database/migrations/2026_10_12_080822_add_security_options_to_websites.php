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
            $table->boolean('hide_from_search')->default(false)->after('last_webhook_result');
            $table->boolean('block_crawlers')->default(false)->after('hide_from_search');
            $table->boolean('basic_auth_enabled')->default(false)->after('block_crawlers');
            $table->string('basic_auth_user')->nullable()->after('basic_auth_enabled');
            // crypt() SHA-512 hash, never the password itself.
            $table->string('basic_auth_hash')->nullable()->after('basic_auth_user');
            $table->text('basic_auth_except')->nullable()->after('basic_auth_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['hide_from_search', 'block_crawlers', 'basic_auth_enabled', 'basic_auth_user', 'basic_auth_hash', 'basic_auth_except']);
        });
    }
};
