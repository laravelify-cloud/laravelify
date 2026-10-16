<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('trash_items', function (Blueprint $table) {
            // The website whose own user keeps this item in its own trash folder (null: the shared trash).
            $table->string('site_slug')->nullable()->after('scope');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('trash_items', function (Blueprint $table) {
            $table->dropColumn('site_slug');
        });
    }
};
