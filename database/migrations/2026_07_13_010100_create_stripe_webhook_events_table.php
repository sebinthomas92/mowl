<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('stripe_event_id')->unique();
            $table->string('type')->index();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stripe_webhook_events ENABLE ROW LEVEL SECURITY');
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE POLICY marketing_owl_app_full_access ON stripe_webhook_events FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true); END IF; END $$;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_policies WHERE schemaname = 'public' AND tablename = 'stripe_webhook_events' AND policyname = 'marketing_owl_app_full_access') THEN DROP POLICY marketing_owl_app_full_access ON stripe_webhook_events; END IF; END $$;");
            DB::statement('ALTER TABLE stripe_webhook_events DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('stripe_webhook_events');
    }
};
