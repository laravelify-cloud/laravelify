<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('domain')->nullable()->unique()->after('ports');
            $table->foreignId('cloudflare_tunnel_id')->nullable()->after('domain')->constrained()->nullOnDelete();
            $table->string('basic_auth_user')->nullable()->after('cloudflare_tunnel_id');
            $table->text('basic_auth_hash')->nullable()->after('basic_auth_user');
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloudflare_tunnel_id');
            $table->dropColumn(['domain', 'basic_auth_user', 'basic_auth_hash']);
        });
    }
};
