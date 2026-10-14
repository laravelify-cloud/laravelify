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
        Schema::table('groups', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('name');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->boolean('enabled')->default(true)->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->dropColumn('enabled');
        });
    }
};
