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
        Schema::create('panel_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('kind');
            $table->string('scope')->index();
            $table->string('status')->default('queued');
            $table->string('label');
            $table->json('payload')->nullable();
            $table->json('result')->nullable();
            $table->unsignedTinyInteger('progress')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('panel_tasks');
    }
};
