<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What came with a message: pasted images, GitHub repositories, and website logs.
     */
    public function up(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->json('attachments')->nullable()->after('tools');
        });
    }

    public function down(): void
    {
        Schema::table('ai_chat_messages', function (Blueprint $table) {
            $table->dropColumn('attachments');
        });
    }
};
