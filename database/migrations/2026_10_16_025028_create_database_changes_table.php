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
        Schema::create('database_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('database', 64);
            $table->string('table', 64)->nullable();
            // insert, update, delete (a row), or create, alter, drop (a trigger, event, procedure, function)
            $table->string('action', 16);
            $table->string('object_type', 16)->default('row');
            $table->string('object_name', 64)->nullable();
            $table->json('keys')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->longText('sql_before')->nullable();
            $table->longText('sql_after')->nullable();
            $table->string('snapshot')->nullable();
            $table->foreignId('reverts_id')->nullable()->constrained('database_changes')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();

            $table->index(['database', 'table']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('database_changes');
    }
};
