<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Landing pages built from approved content items, and publishing them to a store.
 *
 * A page keeps one editable draft. Publishing freezes the draft into a page version (with the exact
 * content versions it used), sends it to the store and verifies that the page address serves it.
 * Rolling back re-publishes an earlier frozen version; the draft is kept.
 */
return new class extends Migration
{
    private const TABLES = ['store_connections', 'pages', 'page_sections', 'page_versions', 'publish_attempts'];

    public function up(): void
    {
        Schema::create('store_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('driver', 20);
            $table->string('site_url', 500);
            $table->string('api_base', 500)->nullable();
            $table->string('username', 120)->nullable();
            $table->text('secret')->nullable();
            $table->string('status', 20)->default('connected');
            $table->string('last_error', 500)->nullable();
            $table->boolean('has_woocommerce')->default(false);
            $table->json('media')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('slug', 120);
            $table->string('headline', 200);
            $table->unsignedInteger('draft_version')->default(1);
            $table->unsignedInteger('live_version')->nullable();
            $table->string('publish_state', 20)->default('idle');
            $table->string('last_error', 500)->nullable();
            $table->string('remote_id', 64)->nullable();
            $table->string('remote_url', 500)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['product_id', 'id']);
        });

        Schema::create('page_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('kind', 20);
            $table->string('title', 120);
            $table->foreignId('content_item_id')->nullable()->constrained()->nullOnDelete();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'position']);
            $table->index('content_item_id');
        });

        Schema::create('page_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('snapshot');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['page_id', 'version']);
        });

        Schema::create('publish_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_connection_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20)->default('publish');
            $table->string('status', 20);
            $table->string('error', 500)->nullable();
            $table->string('remote_url', 500)->nullable();
            $table->longText('html')->nullable();
            $table->unsignedSmallInteger('verify_checks')->default(0);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['page_id', 'id']);
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
