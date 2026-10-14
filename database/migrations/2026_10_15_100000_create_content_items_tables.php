<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every launch pack output (positioning, angles, each video route, offers, channel copy, each banner)
 * becomes its own content item with its own versions, comments and approval. The pack stays as the
 * run record; its versions are assembled from approved items so exports and banners keep working.
 */
return new class extends Migration
{
    private const TABLES = ['content_items', 'content_versions', 'content_comments', 'content_client_links'];

    public function up(): void
    {
        Schema::create('content_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('banner_creative_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 60)->nullable();
            $table->string('type', 20);
            $table->string('title', 160);
            $table->string('status', 20)->default('draft');
            $table->unsignedInteger('current_version')->default(1);
            $table->unsignedInteger('synced_pack_version')->default(0);
            $table->foreignId('variation_of_id')->nullable()->constrained('content_items')->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['campaign_pack_id', 'section']);
            $table->index(['workspace_id', 'status']);
            $table->index(['product_id', 'status']);
        });

        Schema::create('content_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('body');
            $table->string('note', 200)->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('campaign_pack_version_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reviewed_by_name', 120)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['content_item_id', 'version']);
        });

        Schema::create('content_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_name', 120);
            $table->string('author_role', 20)->default('member');
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('content_client_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name', 120);
            $table->string('client_email', 255)->nullable();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'revoked_at']);
        });

        Schema::table('campaign_packs', function (Blueprint $table) {
            $table->json('brief')->nullable();
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
        Schema::table('campaign_packs', fn (Blueprint $table) => $table->dropColumn('brief'));
        foreach (array_reverse(self::TABLES) as $name) {
            Schema::dropIfExists($name);
        }
    }
};
