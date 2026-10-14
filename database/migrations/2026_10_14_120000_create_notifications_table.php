<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'workspace_id', 'read_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ENABLE ROW LEVEL SECURITY');
            DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON notifications TO marketing_owl_app; EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON notifications FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END $$");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
