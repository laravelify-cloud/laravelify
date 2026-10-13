<?php

use App\Models\GithubApp;
use App\Models\PrivateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('private_keys', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->text('private_key');
            $table->text('public_key')->nullable();
            $table->boolean('is_git_related')->default(false);
            $table->timestamps();
        });

        Schema::table('github_apps', function (Blueprint $table) {
            $table->foreignId('private_key_id')->nullable()->after('private_key')->constrained('private_keys')->nullOnDelete();
        });

        if (! Schema::hasTable('github_apps')) {
            return;
        }

        GithubApp::query()->whereNotNull('private_key')->each(function (GithubApp $app): void {
            if ($app->private_key_id || blank($app->private_key)) {
                return;
            }

            $key = PrivateKey::query()->create([
                'name' => 'github-app-'.$app->slug,
                'description' => 'GitHub App '.$app->name,
                'private_key' => $app->private_key,
                'is_git_related' => true,
            ]);

            $app->private_key_id = $key->id;
            $app->private_key = null;
            $app->save();
        });
    }

    public function down(): void
    {
        Schema::table('github_apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('private_key_id');
        });

        Schema::dropIfExists('private_keys');
    }
};
