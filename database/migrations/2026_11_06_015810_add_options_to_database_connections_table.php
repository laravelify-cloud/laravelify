<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The connection dialog's other settings: a SQL Server instance, SSL for MySQL and
     * PostgreSQL, read-only, a timeout and a color to tell connections apart.
     */
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->string('instance', 64)->nullable()->after('port');
            $table->string('ssl_mode', 20)->default('prefer')->after('trust_certificate');
            $table->boolean('read_only')->default(false)->after('ssl_mode');
            $table->unsignedSmallInteger('timeout')->default(8)->after('read_only');
            $table->string('color', 20)->nullable()->after('timeout');
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->dropColumn(['instance', 'ssl_mode', 'read_only', 'timeout', 'color']);
        });
    }
};
