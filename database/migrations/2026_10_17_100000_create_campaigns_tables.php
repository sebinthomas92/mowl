<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns tie an objective, audience and dates to exact approved content versions and a live page.
 *
 * Launching pins those versions, so later revisions never change what a campaign measured. Results
 * are imported per campaign (a CSV export, or orders pulled from the connected store) and kept apart
 * from AI generation usage, which is a production cost.
 */
return new class extends Migration
{
    private const TABLES = ['campaigns', 'campaign_deliverables', 'campaign_results'];

    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('follows_campaign_id')->nullable()->constrained('campaigns')->nullOnDelete();
            $table->string('name', 120);
            $table->string('status', 20)->default('planned');
            $table->string('objective', 200);
            $table->string('offer', 300)->nullable();
            $table->string('audience', 500)->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('hypothesis')->nullable();
            $table->string('baseline', 200)->nullable();
            // The baseline conversion rate in percent, when the baseline states one, so results compare to it.
            $table->decimal('baseline_rate', 6, 3)->nullable();
            $table->string('traffic_source', 200)->nullable();
            $table->unsignedInteger('pinned_page_version')->nullable();
            $table->timestamp('launched_at')->nullable();
            $table->foreignId('launched_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'id']);
            $table->index(['workspace_id', 'status']);
            $table->index('follows_campaign_id');
            $table->index('page_id');
        });

        Schema::create('campaign_deliverables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedInteger('pinned_version')->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'content_item_id']);
            $table->index('content_item_id');
        });

        Schema::create('campaign_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('source', 20);
            $table->string('source_label', 200);
            $table->date('window_start');
            $table->date('window_end');
            $table->string('currency', 3)->nullable();
            $table->string('attribution', 80)->nullable();
            $table->unsignedBigInteger('visits')->nullable();
            $table->unsignedBigInteger('orders')->nullable();
            $table->decimal('revenue', 14, 2)->nullable();
            $table->json('daily');
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['campaign_id', 'id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (self::TABLES as $name) {
                DB::statement("ALTER TABLE {$name} ENABLE ROW LEVEL SECURITY");
                DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON {$name} TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE {$name}_id_seq TO marketing_owl_app; EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON {$name} FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END $$");
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
