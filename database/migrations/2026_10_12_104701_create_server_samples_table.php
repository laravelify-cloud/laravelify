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
        // One row per minute for the Server monitoring page.
        Schema::create('server_samples', function (Blueprint $table) {
            $table->id();
            $table->dateTime('at')->index();
            $table->decimal('cpu', 5, 2)->nullable();
            $table->decimal('memory', 5, 2)->nullable();
            $table->decimal('swap', 5, 2)->nullable();
            $table->decimal('load1', 7, 2)->nullable();
            $table->decimal('disk', 5, 2)->nullable();
            $table->unsignedBigInteger('net_in')->nullable();
            $table->unsignedBigInteger('net_out')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('server_samples');
    }
};
