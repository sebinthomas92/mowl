<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id')->nullable()->unique();
            $table->string('stripe_subscription_id')->nullable()->unique();
            $table->string('stripe_price_id')->nullable();
            $table->string('status')->default('inactive')->index();
            $table->timestamp('current_period_ends_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workspace_subscriptions ENABLE ROW LEVEL SECURITY');
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE POLICY marketing_owl_app_full_access ON workspace_subscriptions FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true); END IF; END $$;");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_policies WHERE schemaname = 'public' AND tablename = 'workspace_subscriptions' AND policyname = 'marketing_owl_app_full_access') THEN DROP POLICY marketing_owl_app_full_access ON workspace_subscriptions; END IF; END $$;");
            DB::statement('ALTER TABLE workspace_subscriptions DISABLE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('workspace_subscriptions');
    }
};
