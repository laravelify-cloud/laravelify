<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A preview on its own random subdomain (through a Cloudflare tunnel), and the tunnel it uses.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->string('preview_host')->nullable()->unique()->after('preview_token');
            $table->foreignId('preview_tunnel_id')->nullable()->after('preview_host')->constrained('cloudflare_tunnels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preview_tunnel_id');
            $table->dropUnique(['preview_host']);
            $table->dropColumn('preview_host');
        });
    }
};
