<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Messages of a panel AI chat: the user's, and the agent's answer as it streams in, with the
     * panel tools it used (tools) and how it ended (status: running, done, stopped, error).
     */
    public function up(): void
    {
        Schema::create('ai_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_chat_id')->constrained()->cascadeOnDelete();
            $table->string('role', 12);
            $table->longText('text');
            $table->json('tools')->nullable();
            $table->string('agent', 20)->nullable();
            $table->string('status', 12)->default('done');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chat_messages');
    }
};
