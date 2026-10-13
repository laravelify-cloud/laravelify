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
        Schema::create('backup_storages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('endpoint')->nullable();
            $table->string('region')->default('us-east-1');
            $table->string('bucket');
            $table->string('path')->default('laravelify');
            $table->text('access_key');
            $table->text('secret_key');
            $table->boolean('is_default')->default(false);
            $table->timestamp('checked_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('backup_storages');
    }
};
