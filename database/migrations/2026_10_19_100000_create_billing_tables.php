<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stripe billing: one subscription row per workspace and a log of processed webhook events.
 *
 * Production already holds empty copies of both tables from an earlier attempt, so each
 * table and column is only created when it is missing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workspace_subscriptions')) {
            Schema::create('workspace_subscriptions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('stripe_customer_id')->nullable()->unique();
                $table->string('stripe_subscription_id')->nullable()->unique();
                $table->string('stripe_price_id')->nullable();
                $table->string('status')->nullable()->index();
                $table->timestamp('current_period_ends_at')->nullable();
                $table->timestamp('stripe_updated_at')->nullable();
                $table->timestamp('canceled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('workspace_subscriptions', 'cancel_at_period_end')) {
            Schema::table('workspace_subscriptions', function (Blueprint $table) {
                $table->boolean('cancel_at_period_end')->default(false);
            });
        }

        if (! Schema::hasTable('stripe_webhook_events')) {
            Schema::create('stripe_webhook_events', function (Blueprint $table) {
                $table->id();
                $table->string('stripe_event_id')->unique();
                $table->string('type')->index();
                $table->json('payload')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['workspace_subscriptions', 'stripe_webhook_events'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON {$table} TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE {$table}_id_seq TO marketing_owl_app; IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = '{$table}' AND policyname = 'marketing_owl_app_full_access') THEN EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON {$table} FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END IF; END $$");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
        Schema::dropIfExists('workspace_subscriptions');
    }
};
