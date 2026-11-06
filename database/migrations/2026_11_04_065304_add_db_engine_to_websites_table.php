<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The kind of database the panel makes for a website: mysql (MySQL or MariaDB), postgresql,
     * mongodb, or sqlite. Websites made before keep MySQL.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('db_engine', 20)->default('mysql')->after('provision_database');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('db_engine');
        });
    }
};
