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
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('branch')->nullable()->after('commit_sha');
            $table->text('commit_message')->nullable()->after('branch');
            $table->string('commit_author')->nullable()->after('commit_message');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('auto_deploy')->default(true)->after('webhook_secret');
            $table->timestamp('last_webhook_at')->nullable()->after('auto_deploy');
            $table->string('last_webhook_result')->nullable()->after('last_webhook_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['branch', 'commit_message', 'commit_author']);
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['auto_deploy', 'last_webhook_at', 'last_webhook_result']);
        });
    }
};
