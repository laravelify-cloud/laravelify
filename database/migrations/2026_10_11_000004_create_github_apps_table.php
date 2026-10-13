<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('organization')->nullable();
            $table->string('html_url')->default('https://github.com');
            $table->string('api_url')->default('https://api.github.com');
            $table->string('git_user')->default('git');
            $table->unsignedSmallInteger('git_port')->default(22);
            $table->string('app_id')->nullable();
            $table->string('installation_id')->nullable();
            $table->text('client_id')->nullable();
            $table->text('client_secret')->nullable();
            $table->text('webhook_secret')->nullable();
            $table->text('private_key')->nullable();
            $table->timestamps();
        });

        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('github_app_id')->nullable()->after('cloudflare_tunnel_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('github_app_id');
        });

        Schema::dropIfExists('github_apps');
    }
};
