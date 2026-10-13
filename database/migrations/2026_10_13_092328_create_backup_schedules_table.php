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
        Schema::create('backup_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('kind');
            $table->foreignId('backup_storage_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->string('frequency')->default('daily');
            $table->unsignedSmallInteger('interval')->default(1);
            $table->string('run_time')->default('02:00');
            $table->unsignedTinyInteger('weekday')->default(0);
            $table->string('cron');
            $table->json('targets');
            $table->unsignedSmallInteger('retention_count')->default(7);
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_schedules');
    }
};
