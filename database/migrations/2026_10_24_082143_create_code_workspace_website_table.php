<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The projects (websites) in each workspace. A deleted website leaves its workspaces.
     */
    public function up(): void
    {
        Schema::create('code_workspace_website', function (Blueprint $table) {
            $table->id();
            $table->foreignId('code_workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained()->cascadeOnDelete();
            $table->unique(['code_workspace_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('code_workspace_website');
    }
};
