<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_generation_jobs', function (Blueprint $table): void {
            $table->unsignedInteger('provider_latency_ms')->nullable()->after('provider_request_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_generation_jobs', function (Blueprint $table): void {
            $table->dropColumn('provider_latency_ms');
        });
    }
};
