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

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE campaign_pack_version_comments, campaign_pack_shares TO marketing_owl_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE campaign_pack_version_comments_id_seq, campaign_pack_shares_id_seq TO marketing_owl_app');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE campaign_pack_version_comments, campaign_pack_shares FROM marketing_owl_app');
        DB::statement('REVOKE USAGE, SELECT ON SEQUENCE campaign_pack_version_comments_id_seq, campaign_pack_shares_id_seq FROM marketing_owl_app');
    }
};
