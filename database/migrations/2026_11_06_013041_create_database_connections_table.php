<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases outside this server (SQL Server, MySQL, PostgreSQL) the Database tool can open.
     */
    public function up(): void
    {
        Schema::create('database_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('driver', 20);
            $table->string('host');
            $table->unsignedInteger('port');
            $table->string('database');
            $table->string('username');
            $table->text('password')->nullable();
            $table->boolean('encrypt')->default(false);
            $table->boolean('trust_certificate')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_connections');
    }
};
