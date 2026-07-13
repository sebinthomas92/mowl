<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION public.prevent_workspace_audit_event_mutation()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog
            AS $$
            BEGIN
                RAISE EXCEPTION 'workspace audit events are immutable';
            END;
            $$;

            CREATE TRIGGER workspace_audit_events_immutable
            BEFORE UPDATE OR DELETE ON public.workspace_audit_events
            FOR EACH ROW EXECUTE FUNCTION public.prevent_workspace_audit_event_mutation();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS workspace_audit_events_immutable ON public.workspace_audit_events');
        DB::unprepared('DROP FUNCTION IF EXISTS public.prevent_workspace_audit_event_mutation()');
    }
};
