<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_credits', function (Blueprint $table) {
            $table->foreignId('campaign_generation_job_id')
                ->nullable()
                ->after('campaign_pack_id')
                ->constrained('campaign_generation_jobs')
                ->nullOnDelete();
            $table->unique(
                ['campaign_generation_job_id', 'event'],
                'workspace_credit_job_event_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workspace_credits', function (Blueprint $table) {
            $table->dropUnique('workspace_credit_job_event_unique');
            $table->dropConstrainedForeignId('campaign_generation_job_id');
        });
    }
};
