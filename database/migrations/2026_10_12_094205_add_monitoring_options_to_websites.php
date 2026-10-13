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
            $table->unsignedSmallInteger('monitor_retention_days')->default(7)->after('basic_auth_except');
            $table->unsignedInteger('monitor_slow_ms')->default(2000)->after('monitor_retention_days');
            $table->unsignedInteger('monitor_error_threshold')->default(20)->after('monitor_slow_ms');
            // One IP or CIDR range per line; nginx answers them with 403.
            $table->text('blocked_ips')->nullable()->after('monitor_error_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn(['monitor_retention_days', 'monitor_slow_ms', 'monitor_error_threshold', 'blocked_ips']);
        });
    }
};
