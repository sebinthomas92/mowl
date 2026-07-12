<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_generation_jobs', function (Blueprint $table) {
            $table->string('idempotency_key')->nullable()->unique()->after('base_version');
            $table->timestamp('heartbeat_at')->nullable()->after('started_at');
        });

        Schema::create('campaign_job_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_generation_job_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('phase')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['campaign_generation_job_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE campaign_job_events ENABLE ROW LEVEL SECURITY');
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE POLICY marketing_owl_app_full_access ON campaign_job_events FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true); END IF; END $$;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_policies WHERE schemaname = 'public' AND tablename = 'campaign_job_events' AND policyname = 'marketing_owl_app_full_access') THEN DROP POLICY marketing_owl_app_full_access ON campaign_job_events; END IF; END $$;");
            DB::statement('ALTER TABLE campaign_job_events DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('campaign_job_events');

        Schema::table('campaign_generation_jobs', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn(['idempotency_key', 'heartbeat_at']);
        });
    }
};
