<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_resource_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('label');
            $table->text('url');
            $table->timestamps();
            $table->unique(['product_id', 'kind']);
        });

        Schema::create('product_hub_shares', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            foreach (['product_resource_links', 'product_hub_shares'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("CREATE POLICY marketing_owl_app_full_access ON {$table} FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('product_hub_shares');
        Schema::dropIfExists('product_resource_links');
    }
};
