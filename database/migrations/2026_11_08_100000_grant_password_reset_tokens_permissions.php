<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lets the app role read and write password reset tokens, for "Forgot password?".
 * The table has had row level security on since 2026_07_11 but no policy for the app role.
 * Safe to run more than once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN GRANT SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens TO marketing_owl_app; IF NOT EXISTS (SELECT 1 FROM pg_policies WHERE tablename = 'password_reset_tokens' AND policyname = 'marketing_owl_app_full_access') THEN EXECUTE 'CREATE POLICY marketing_owl_app_full_access ON password_reset_tokens FOR ALL TO marketing_owl_app USING (true) WITH CHECK (true)'; END IF; END IF; END $$");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP POLICY IF EXISTS marketing_owl_app_full_access ON password_reset_tokens');
        DB::statement("DO $$ BEGIN IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN REVOKE SELECT, INSERT, UPDATE, DELETE ON password_reset_tokens FROM marketing_owl_app; END IF; END $$");
    }
};
