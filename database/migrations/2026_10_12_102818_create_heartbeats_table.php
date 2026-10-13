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
        // A cron job or scheduled task calls the heartbeat URL when it finishes.
        // No call within the interval (plus grace) means it stopped running.
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('token', 40)->unique();
            $table->unsignedInteger('interval_minutes');
            $table->unsignedInteger('grace_minutes')->default(2);
            $table->dateTime('last_ping_at')->nullable();
            $table->dateTime('late_since')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
