<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Audit events and support notes are append-only (a trigger and the model also reject changes).
        DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN
            GRANT SELECT, INSERT ON TABLE workspace_audit_events, workspace_support_notes TO marketing_owl_app;
            GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE workspace_onboarding_states TO marketing_owl_app;
            GRANT USAGE, SELECT ON SEQUENCE workspace_audit_events_id_seq, workspace_support_notes_id_seq, workspace_onboarding_states_id_seq TO marketing_owl_app;
        END IF; END $$");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN
            REVOKE SELECT, INSERT ON TABLE workspace_audit_events, workspace_support_notes FROM marketing_owl_app;
            REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE workspace_onboarding_states FROM marketing_owl_app;
            REVOKE USAGE, SELECT ON SEQUENCE workspace_audit_events_id_seq, workspace_support_notes_id_seq, workspace_onboarding_states_id_seq FROM marketing_owl_app;
        END IF; END $$");
    }
};
