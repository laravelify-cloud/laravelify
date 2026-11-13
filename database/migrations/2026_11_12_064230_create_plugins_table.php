<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Services installed from the Plugins page (Docker compose under /opt/laravelify/plugins/<key>).
     * Credentials, Vault's unseal keys and root token among them, are encrypted.
     */
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('status', 20)->default('installing');
            $table->json('ports');
            $table->text('credentials')->nullable();
            $table->timestamp('credentials_shown_at')->nullable();
            $table->boolean('sealed')->nullable();
            $table->boolean('auto_unseal')->default(false);
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugins');
    }
};
