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
        Schema::create('backup_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_schedule_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('backup_storage_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('source_run_id')->nullable()->constrained('backup_runs')->nullOnDelete();
            $table->string('type')->default('backup');
            $table->string('kind');
            $table->string('target');
            $table->string('status')->default('queued');
            $table->string('trigger')->default('schedule');
            $table->string('object_key')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('meta')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index(['backup_schedule_id', 'status']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_runs');
    }
};
