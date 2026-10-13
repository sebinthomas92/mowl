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

        DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE product_resource_links, product_hub_shares TO marketing_owl_app');
        DB::statement('GRANT USAGE, SELECT ON SEQUENCE product_resource_links_id_seq, product_hub_shares_id_seq TO marketing_owl_app');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('REVOKE SELECT, INSERT, UPDATE, DELETE ON TABLE product_resource_links, product_hub_shares FROM marketing_owl_app');
        DB::statement('REVOKE USAGE, SELECT ON SEQUENCE product_resource_links_id_seq, product_hub_shares_id_seq FROM marketing_owl_app');
    }
};
