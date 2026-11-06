<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A SQLite file is a connection too: no host, port or login, only its path (in database).
     */
    public function up(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->string('host')->nullable()->change();
            $table->unsignedInteger('port')->default(0)->change();
            $table->string('database', 1024)->change();
            $table->string('username')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('database_connections', function (Blueprint $table) {
            $table->string('host')->nullable(false)->change();
            $table->unsignedInteger('port')->change();
            $table->string('database')->change();
            $table->string('username')->nullable(false)->change();
        });
    }
};
