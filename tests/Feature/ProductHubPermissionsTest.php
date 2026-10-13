<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductHubPermissionsTest extends TestCase
{
    public function test_postgres_app_role_can_use_hub_tables_after_permissions_migration(): void
    {
        $url = getenv('PRODUCT_HUB_TEST_DATABASE_URL');
        if (! $url) {
            $this->markTestSkipped('Set PRODUCT_HUB_TEST_DATABASE_URL to a disposable PostgreSQL test database.');
        }

        $this->assertStringEndsWith('_test', trim(parse_url($url, PHP_URL_PATH), '/'));
        config(['database.connections.hub_permissions' => [
            'driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '',
        ]]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('hub_permissions');
        $db = DB::connection();
        $schema = 'hub_permissions_'.bin2hex(random_bytes(6));
        $db->beginTransaction();

        try {
            $db->statement("CREATE SCHEMA {$schema}");
            $db->statement("SET LOCAL search_path TO {$schema}");
            $db->statement("DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE ROLE marketing_owl_app; END IF; END $$");
            $db->statement("GRANT USAGE ON SCHEMA {$schema} TO marketing_owl_app");
            foreach (['workspaces', 'products', 'users'] as $table) {
                $db->statement("CREATE TABLE {$table} (id bigint PRIMARY KEY)");
                $db->table($table)->insert(['id' => 1]);
            }

            $create = require database_path('migrations/2026_07_14_120000_create_product_hub_tables.php');
            $create->up();
            // Reproduce production's restricted role, without schema-owner privileges.
            $db->statement('SAVEPOINT before_read');
            $db->statement('SET LOCAL ROLE marketing_owl_app');
            try {
                $db->table('product_resource_links')->get();
                $this->fail('The original migration must reproduce the missing-grant error.');
            } catch (QueryException $exception) {
                $this->assertSame('42501', $exception->errorInfo[0]);
            } finally {
                $db->statement('ROLLBACK TO SAVEPOINT before_read');
            }

            $fix = require database_path('migrations/2026_10_13_100000_grant_product_hub_table_permissions.php');
            $fix->up();
            $fix->up(); // Granting again must be safe for an already-repaired database.
            $db->statement('SET LOCAL ROLE marketing_owl_app');

            $link = $db->table('product_resource_links')->insertGetId([
                'workspace_id' => 1, 'product_id' => 1, 'kind' => 'product_page',
                'label' => 'Product page', 'url' => 'https://example.com/product',
            ]);
            $share = $db->table('product_hub_shares')->insertGetId([
                'workspace_id' => 1, 'product_id' => 1, 'created_by_user_id' => 1,
                'token' => str_repeat('a', 64),
            ]);
            $this->assertGreaterThan(0, $link);
            $this->assertGreaterThan(0, $share);
            $this->assertSame('Product page', $db->table('product_resource_links')->where('id', $link)->value('label'));
            $this->assertSame(1, $db->table('product_resource_links')->where('id', $link)->update(['label' => 'Updated page']));
            $this->assertSame(1, $db->table('product_hub_shares')->where('id', $share)->update(['revoked_at' => now()]));
            $this->assertNotNull($db->table('product_hub_shares')->where('id', $share)->value('revoked_at'));
            $this->assertSame(1, $db->table('product_resource_links')->where('id', $link)->delete());
            $this->assertSame(1, $db->table('product_hub_shares')->where('id', $share)->delete());
            $db->statement('RESET ROLE');
            foreach (['product_resource_links', 'product_hub_shares'] as $table) {
                $this->assertTrue((bool) $db->scalar("SELECT relrowsecurity FROM pg_class WHERE oid = '{$schema}.{$table}'::regclass"));
                $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('public', '{$schema}.{$table}', 'SELECT')"));
            }
        } finally {
            $db->rollBack();
            DB::purge('hub_permissions');
            DB::setDefaultConnection($previous);
        }
    }
}
