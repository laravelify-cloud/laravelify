<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VS Code workspaces: one or more projects opened together. A workspace of one project opens
     * that website's own editor; one of several runs its own editor as its own Unix user
     * (lfy-ws--<key>). share_token, while set, lets anyone with the link open the editor.
     */
    public function up(): void
    {
        Schema::create('code_workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('key', 16)->unique();
            $table->string('name');
            $table->unsignedInteger('code_server_port')->nullable();
            $table->string('code_server_token', 32)->nullable()->unique();
            $table->string('share_token', 48)->nullable()->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_workspaces');
    }
};
