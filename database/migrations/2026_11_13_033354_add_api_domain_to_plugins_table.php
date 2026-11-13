<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A plugin's API on a public domain, apart from its web page's domain behind basic auth
 * (MinIO: s3.example.com for the S3 API and image links, minio.example.com for the console).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->string('api_domain')->nullable()->unique()->after('basic_auth_hash');
            $table->foreignId('api_cloudflare_tunnel_id')->nullable()->after('api_domain')->constrained('cloudflare_tunnels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('plugins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('api_cloudflare_tunnel_id');
            $table->dropColumn('api_domain');
        });
    }
};
