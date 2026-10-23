<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A website can have environments (development, staging…): each is a website of its own that
     * deploys another branch of the same repository, listed under the main one.
     */
    public function up(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('group_id')->constrained('websites')->nullOnDelete();
            $table->string('environment', 40)->nullable()->after('parent_id');
        });
    }

    public function down(): void
    {
        Schema::table('websites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('environment');
        });
    }
};
