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
        Schema::create('encrypted_columns', function (Blueprint $table) {
            $table->id();
            $table->string('database', 64);
            $table->string('table', 64);
            $table->string('column', 64);
            $table->foreignId('crypto_method_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['database', 'table', 'column']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('encrypted_columns');
    }
};
