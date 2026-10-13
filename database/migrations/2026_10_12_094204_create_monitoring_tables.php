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
        // One row per request, read from the site's nginx metrics log. Kept for the
        // site's retention period (Monitoring tab), then pruned daily.
        Schema::create('site_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->dateTime('at');
            $table->string('method', 10);
            $table->string('uri', 500);
            $table->string('route', 255);
            $table->unsignedSmallInteger('status');
            $table->unsignedInteger('duration_ms');
            $table->unsignedInteger('bytes')->default(0);
            $table->string('ip', 45);
            $table->string('country', 2)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('referer', 255)->nullable();
            $table->string('threat', 120)->nullable();
            $table->unsignedTinyInteger('risk')->default(0);

            $table->index(['website_id', 'at']);
            $table->index(['website_id', 'risk', 'at']);
        });

        // Per-minute totals with a latency histogram, so charts and p95 stay fast
        // over 30 days without scanning every request.
        Schema::create('request_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->dateTime('minute');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('client_errors')->default(0);
            $table->unsignedInteger('server_errors')->default(0);
            $table->unsignedBigInteger('duration_sum')->default(0);
            $table->unsignedInteger('duration_max')->default(0);
            $table->text('histogram');

            $table->unique(['website_id', 'minute']);
        });

        Schema::create('uptime_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->boolean('ok');
            $table->unsignedSmallInteger('status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error', 255)->nullable();
            $table->dateTime('checked_at');

            $table->index(['website_id', 'checked_at']);
        });

        // Laravel log errors grouped by exception and place, like Nightwatch issues.
        Schema::create('site_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('fingerprint', 40);
            $table->string('level', 20);
            $table->string('exception', 255)->nullable();
            $table->text('message');
            $table->string('file', 500)->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->text('trace')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->string('status', 20)->default('open');
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['website_id', 'fingerprint']);
            $table->index(['website_id', 'status', 'last_seen_at']);
        });

        // How far each log file has been read.
        Schema::create('log_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('path', 500);
            $table->unsignedBigInteger('inode')->default(0);
            $table->unsignedBigInteger('offset')->default(0);
            $table->timestamps();

            $table->unique(['website_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('log_cursors');
        Schema::dropIfExists('site_issues');
        Schema::dropIfExists('uptime_checks');
        Schema::dropIfExists('request_minutes');
        Schema::dropIfExists('site_requests');
    }
};
