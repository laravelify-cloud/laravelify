<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * VS Code's Live Server: the dev server running in the editor (npm run dev, php -S...), shown
     * on a random host name through the Cloudflare tunnel while it runs. The key lets that
     * website's editor (and nothing else) turn it on and off.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('live_key', 64)->nullable();
            $table->string('live_host')->nullable();
            $table->unsignedInteger('live_port')->nullable();
            $table->foreignId('live_tunnel_id')->nullable()->constrained('cloudflare_tunnels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('live_tunnel_id');
            $table->dropColumn(['live_key', 'live_host', 'live_port']);
        });
    }
};
