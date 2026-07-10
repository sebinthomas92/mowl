<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('campaign_generation_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('queued')->index();
            $table->string('phase')->default('waiting');
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('analysis_mode')->default('standard');
            $table->unsignedTinyInteger('credit_cost')->default(1);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->boolean('cache_hit')->default(false);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('cached_input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('estimated_cost', 10, 6)->default(0);
            $table->boolean('cost_alert')->default(false);
            $table->string('provider_request_id')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_generation_jobs');
    }
};
