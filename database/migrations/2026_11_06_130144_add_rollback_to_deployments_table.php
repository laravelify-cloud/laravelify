<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rollbacks: a deployment can put an older commit live (target_sha) instead of the newest one
     * on the branch, and keeps the code that was live before it as a .tar.gz (snapshot_path).
     */
    public function up(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->string('target_sha', 64)->nullable()->after('commit_sha');
            $table->string('snapshot_path')->nullable()->after('target_sha');
        });
    }

    public function down(): void
    {
        Schema::table('deployments', function (Blueprint $table) {
            $table->dropColumn(['target_sha', 'snapshot_path']);
        });
    }
};
