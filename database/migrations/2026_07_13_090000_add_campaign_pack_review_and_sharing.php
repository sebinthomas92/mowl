<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_pack_versions', function (Blueprint $table): void {
            $table->string('review_status')->default('draft')->after('generator');
            $table->foreignId('reviewed_by_user_id')->nullable()->after('review_status')->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_user_id');
            $table->text('review_note')->nullable()->after('reviewed_at');
            $table->unique(['campaign_pack_id', 'version']);
        });

        Schema::table('source_snapshots', function (Blueprint $table): void {
            $table->foreignId('approved_by_user_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->foreignId('refreshed_from_snapshot_id')->nullable()->after('approved_at')->constrained('source_snapshots')->nullOnDelete();
        });

        Schema::create('campaign_pack_version_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_pack_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('campaign_pack_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('campaign_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['campaign_pack_version_comments', 'campaign_pack_shares'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY marketing_owl_app_full_access ON {$table} FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_pack_shares');
        Schema::dropIfExists('campaign_pack_version_comments');
        Schema::table('source_snapshots', fn (Blueprint $table) => $table->dropConstrainedForeignId('refreshed_from_snapshot_id'));
        Schema::table('source_snapshots', fn (Blueprint $table) => $table->dropConstrainedForeignId('approved_by_user_id'));
        Schema::table('source_snapshots', fn (Blueprint $table) => $table->dropColumn('approved_at'));
        Schema::table('campaign_pack_versions', function (Blueprint $table): void {
            $table->dropUnique(['campaign_pack_id', 'version']);
            $table->dropConstrainedForeignId('reviewed_by_user_id');
            $table->dropColumn(['review_status', 'reviewed_at', 'review_note']);
        });
    }
};
