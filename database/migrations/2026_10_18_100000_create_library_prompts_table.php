<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Licensed prompt collections shown in the Workflows prompt library.
 *
 * Prompts live in the database rather than in the repository because some collections are
 * licensed for listing inside the app only, and the repository is public. Rows are grouped
 * into packs by `pack`; `credit` and `source_url` keep each prompt's attribution.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('library_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('collection', 60);
            $table->string('source_key', 120);
            $table->string('pack', 120);
            $table->string('category', 30)->default('web');
            $table->string('title', 200);
            $table->longText('body');
            $table->string('tier', 20)->default('free');
            $table->unsignedInteger('position')->default(0);
            $table->string('credit', 200)->nullable();
            $table->string('source_url', 500)->nullable();
            $table->timestamps();

            $table->unique(['collection', 'source_key']);
            $table->index(['collection', 'pack', 'position']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE library_prompts ENABLE ROW LEVEL SECURITY');
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON library_prompts TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE library_prompts_id_seq TO marketing_owl_app; EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON library_prompts FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END $$");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('library_prompts');
    }
};
