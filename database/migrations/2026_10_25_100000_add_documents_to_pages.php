<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The page builder: a page's draft becomes one structured document (theme plus ordered sections with
 * typed settings), edited in the full-screen editor and frozen into page versions on publish.
 *
 * document_revision counts saves, so a save or an AI change made against an older revision is
 * rejected instead of overwriting newer edits. page_generations records each AI run on a page.
 * Pages created before this keep their page_sections until they are opened in the editor.
 * publish_attempts.warning notes what a store changed in a page it accepted (stripped layout styles).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->json('document')->nullable();
            $table->unsignedInteger('document_revision')->default(0);
            $table->string('template', 40)->nullable();
        });

        Schema::table('publish_attempts', function (Blueprint $table) {
            $table->string('warning', 500)->nullable();
        });

        Schema::create('page_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 20);
            $table->string('status', 20);
            $table->text('instruction')->nullable();
            $table->string('target', 64)->nullable();
            $table->unsignedInteger('base_revision');
            $table->unsignedInteger('result_revision')->nullable();
            $table->string('model', 80)->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('cost_usd', 10, 5)->nullable();
            $table->unsignedSmallInteger('credit_cost')->default(0);
            $table->string('error', 500)->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'id']);
            $table->index(['workspace_id', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE page_generations ENABLE ROW LEVEL SECURITY');
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON page_generations TO marketing_owl_app; GRANT USAGE, SELECT ON SEQUENCE page_generations_id_seq TO marketing_owl_app; IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'page_generations' AND policyname = 'marketing_owl_app_full_access') THEN EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON page_generations FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END IF; END $$");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('page_generations');
        Schema::table('publish_attempts', function (Blueprint $table) {
            $table->dropColumn('warning');
        });
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['document', 'document_revision', 'template']);
        });
    }
};
