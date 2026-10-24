<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chats of the panel's AI assistant: one agent (Claude Code, Cursor Agent, Copilot CLI) and
     * model at a time, and the agent's own session id so the next message continues it.
     */
    public function up(): void
    {
        Schema::create('ai_chats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title')->default('New chat');
            $table->string('agent', 20)->default('claude');
            $table->string('model', 120)->nullable();
            $table->string('session_id', 120)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_chats');
    }
};
