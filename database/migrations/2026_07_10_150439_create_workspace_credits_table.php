<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('workspace_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_pack_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount');
            $table->string('event')->index();
            $table->string('description');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        $now = now();
        $credits = DB::table('workspaces')->get()->map(fn ($workspace) => [
            'workspace_id' => $workspace->id,
            'amount' => 50,
            'event' => 'beta_allocation',
            'description' => 'Initial beta pack credits',
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        if ($credits !== []) {
            DB::table('workspace_credits')->insert($credits);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workspace_credits');
    }
};
