<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('banner_logo_disk')->nullable();
            $table->text('banner_logo_path')->nullable();
            $table->string('banner_logo_mime_type')->nullable();
            $table->string('primary_color', 7)->nullable();
        });

        Schema::create('banner_generation_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind')->index();
            $table->string('included_key')->nullable()->unique();
            $table->unsignedTinyInteger('requested_count');
            $table->unsignedInteger('credit_cost')->default(0);
            $table->string('status')->default('queued')->index();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_text_tokens')->default(0);
            $table->unsignedBigInteger('output_image_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->boolean('cost_alert')->default(false);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_pack_id', 'created_at']);
        });

        Schema::create('banner_creatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('banner_generation_batch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_version_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->string('direction');
            $table->string('layout');
            $table->text('headline');
            $table->text('supporting_text')->nullable();
            $table->string('cta')->default('Shop now');
            $table->text('prompt');
            $table->string('status')->default('queued')->index();
            $table->string('disk');
            $table->text('background_path')->nullable();
            $table->text('output_path')->nullable();
            $table->string('output_mime_type')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_text_tokens')->default(0);
            $table->unsignedBigInteger('output_image_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->string('provider_request_id')->nullable();
            $table->unsignedInteger('provider_latency_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['banner_generation_batch_id', 'sequence'], 'banner_creative_batch_sequence_unique');
            $table->index(['campaign_pack_id', 'created_at']);
        });

        Schema::table('workspace_credits', function (Blueprint $table): void {
            $table->foreignId('banner_generation_batch_id')
                ->nullable()
                ->after('campaign_generation_job_id')
                ->constrained('banner_generation_batches')
                ->nullOnDelete();
            $table->unique(
                ['banner_generation_batch_id', 'event'],
                'workspace_credit_banner_event_unique',
            );
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE banner_generation_batches ENABLE ROW LEVEL SECURITY');
            DB::statement('ALTER TABLE banner_creatives ENABLE ROW LEVEL SECURITY');
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE banner_generation_batches, banner_creatives TO marketing_owl_app');
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE banner_generation_batches_id_seq, banner_creatives_id_seq TO marketing_owl_app');
            DB::statement('CREATE POLICY marketing_owl_app_full_access ON banner_generation_batches FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)');
            DB::statement('CREATE POLICY marketing_owl_app_full_access ON banner_creatives FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)');
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE banner_generation_batches, banner_creatives FROM marketing_owl_app');
            DB::statement('REVOKE USAGE, SELECT ON SEQUENCE banner_generation_batches_id_seq, banner_creatives_id_seq FROM marketing_owl_app');
        }

        Schema::table('workspace_credits', function (Blueprint $table): void {
            $table->dropUnique('workspace_credit_banner_event_unique');
            $table->dropConstrainedForeignId('banner_generation_batch_id');
        });

        Schema::dropIfExists('banner_creatives');
        Schema::dropIfExists('banner_generation_batches');

        Schema::table('brands', function (Blueprint $table): void {
            $table->dropColumn(['banner_logo_disk', 'banner_logo_path', 'banner_logo_mime_type', 'primary_color']);
        });
    }
};
