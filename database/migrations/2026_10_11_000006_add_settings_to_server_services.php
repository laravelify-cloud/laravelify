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
        Schema::table('server_services', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('unit');
            $table->json('settings')->nullable()->after('domain');
        });

        foreach (['mysql', 'redis', 'phpmyadmin'] as $key) {
            $defaults = ServerCatalog::connectionDefaults($key);

            DB::table('server_services')->where('key', $key)->update([
                'domain' => $defaults['domain'],
                'settings' => json_encode($defaults['settings']),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('server_services', function (Blueprint $table) {
            $table->dropColumn(['domain', 'settings']);
        });
    }
};
