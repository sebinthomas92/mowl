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
        Schema::table('campaign_packs', function (Blueprint $table) {
            $table->string('analysis_mode')->default('standard')->after('status');
            $table->unsignedTinyInteger('credit_cost')->default(1)->after('analysis_mode');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaign_packs', function (Blueprint $table) {
            $table->dropColumn(['analysis_mode', 'credit_cost']);
        });
    }
};
