<?php

use App\Services\ServerCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('cloudflare_tunnels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('token');
            $table->text('ingress')->nullable();
            $table->timestamps();
        });

        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cloudflare_tunnel_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type');
            $table->string('domain')->nullable()->unique();
            $table->string('repository')->nullable();
            $table->string('branch')->default('main');
            $table->string('php_version')->nullable();
            $table->string('node_version')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->unsignedInteger('http_port')->nullable()->unique();
            $table->unsignedInteger('reverb_port')->nullable();
            $table->boolean('enable_reverb')->default(false);
            $table->boolean('provision_database')->default(false);
            $table->string('db_name')->nullable();
            $table->string('db_username')->nullable();
            $table->text('db_password')->nullable();
            $table->string('deploy_path');
            $table->text('env_content')->nullable();
            $table->text('nginx_config')->nullable();
            $table->text('process_config')->nullable();
            $table->string('webhook_secret');
            $table->timestamps();
        });

        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('trigger');
            $table->string('commit_sha')->nullable();
            $table->longText('log')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('server_services', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('kind');
            $table->string('version')->nullable();
            $table->text('config');
            $table->string('unit')->nullable();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach (ServerCatalog::definitions() as $service) {
            DB::table('server_services')->insert([
                ...$service,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('server_services');
        Schema::dropIfExists('deployments');
        Schema::dropIfExists('websites');
        Schema::dropIfExists('cloudflare_tunnels');
        Schema::dropIfExists('groups');
    }
};
