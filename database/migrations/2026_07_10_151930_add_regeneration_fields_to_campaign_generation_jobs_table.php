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
        Schema::table('campaign_generation_jobs', function (Blueprint $table) {
            $table->string('section')->nullable()->after('analysis_mode');
            $table->unsignedInteger('base_version')->nullable()->after('section');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_generation_jobs', function (Blueprint $table) {
            $table->dropColumn(['section', 'base_version']);
        });
    }
};
