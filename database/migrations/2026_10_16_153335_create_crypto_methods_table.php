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
        Schema::create('crypto_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // The code runs in this website's folder, as its user (its helpers and keys are there).
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->boolean('boot_laravel')->default(true);
            $table->text('encrypt_code');
            $table->text('decrypt_code')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('crypto_methods');
    }
};
