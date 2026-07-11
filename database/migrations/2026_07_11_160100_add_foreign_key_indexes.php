<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $indexes = [
        'brands' => ['workspace_id'],
        'products' => ['brand_id'],
        'source_snapshots' => ['product_id'],
        'campaign_packs' => ['product_id', 'source_snapshot_id'],
        'campaign_pack_versions' => ['campaign_pack_id'],
        'campaign_generation_jobs' => ['workspace_id', 'campaign_pack_id', 'source_snapshot_id'],
        'workspace_credits' => ['campaign_pack_id'],
        'media_assets' => ['workspace_id', 'source_snapshot_id'],
        'workspace_user' => ['user_id'],
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $columns) {
            Schema::table($table, function (Blueprint $blueprint) use ($columns): void {
                foreach ($columns as $column) {
                    $blueprint->dropIndex([$column]);
                }
            });
        }
    }
};
