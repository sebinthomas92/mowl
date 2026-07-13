<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private array $tables = [
        'workspace_audit_events',
        'workspace_support_notes',
        'workspace_onboarding_states',
    ];

    public function up(): void
    {
        Schema::table('workspace_credits', function (Blueprint $table): void {
            $table->string('idempotency_key')->nullable()->unique()->after('description');
        });

        Schema::create('workspace_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event')->index();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('workspace_support_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('workspace_onboarding_states', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('completed_steps')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->tables as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY marketing_owl_app_full_access ON {$table} FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->tables as $table) {
                DB::statement("DROP POLICY IF EXISTS marketing_owl_app_full_access ON {$table}");
            }
        }

        Schema::dropIfExists('workspace_onboarding_states');
        Schema::dropIfExists('workspace_support_notes');
        Schema::dropIfExists('workspace_audit_events');

        Schema::table('workspace_credits', function (Blueprint $table): void {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });
    }
};
