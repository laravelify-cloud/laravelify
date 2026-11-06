<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every SQL run from the Database tool's SQL tab, per database: what ran, what came back, and
     * for UPDATE and DELETE the rows before and after.
     */
    public function up(): void
    {
        Schema::create('sql_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('engine', 20);
            $table->string('database', 120);
            $table->mediumText('sql');
            $table->string('kind', 10);
            $table->boolean('ok')->default(true);
            $table->unsignedInteger('rows')->nullable();
            $table->unsignedInteger('affected')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->json('changes')->nullable();
            $table->timestamps();

            $table->index(['engine', 'database', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sql_histories');
    }
};
