<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->string('key', 40);
            $table->text('source_value')->nullable();
            $table->text('confirmed_value')->nullable();
            $table->string('status', 20)->default('missing');
            $table->foreignId('confirmed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('source_checked_at')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'key']);
            $table->index(['workspace_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE product_facts ENABLE ROW LEVEL SECURITY');
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON product_facts TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE product_facts_id_seq TO marketing_owl_app; EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON product_facts FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END $$");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_facts');
    }
};
