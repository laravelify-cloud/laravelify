<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * Dates now follow each browser's timezone and country unless a setting is chosen. Earlier
 * versions saved their defaults (UTC, 2026-10-12, 14:05) on the first save of the settings
 * page, so those exact defaults become Automatic. Anything else someone picked stays.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $defaults = ['display_timezone' => ['UTC', ''], 'display_date_format' => ['Y-m-d', 'auto'], 'display_time_format' => ['H:i', 'auto']];

        foreach ($defaults as $key => [$old, $automatic]) {
            if (Setting::getValue($key) === $old) {
                Setting::putValue($key, $automatic);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
