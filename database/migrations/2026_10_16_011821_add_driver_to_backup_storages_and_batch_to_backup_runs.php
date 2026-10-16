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
        Schema::table('backup_storages', function (Blueprint $table) {
            $table->string('driver')->default('s3')->after('name');
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            $table->string('batch', 36)->nullable()->after('backup_storage_id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropIndex(['batch']);
            $table->dropColumn('batch');
        });

        Schema::table('backup_storages', function (Blueprint $table) {
            $table->dropColumn('driver');
        });
    }
};
