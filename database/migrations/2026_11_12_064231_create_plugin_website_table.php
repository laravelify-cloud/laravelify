<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Websites linked to a plugin get its variables in their .env; env_keys are the names set,
     * so unlinking removes exactly those.
     */
    public function up(): void
    {
        Schema::create('plugin_website', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plugin_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->json('env_keys');
            $table->timestamps();
            $table->unique(['plugin_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_website');
    }
};
