<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Production connects as the restricted marketing_owl_app role; every pages table must work for it. */
class PagesPermissionsTest extends TestCase
{
    public function test_postgres_app_role_can_use_the_pages_tables(): void
    {
        $url = getenv('PRODUCT_HUB_TEST_DATABASE_URL');
        if (! $url) {
            $this->markTestSkipped('Set PRODUCT_HUB_TEST_DATABASE_URL to a disposable PostgreSQL test database.');
        }

        $this->assertStringEndsWith('_test', trim(parse_url($url, PHP_URL_PATH), '/'));
        config(['database.connections.pages_permissions' => ['driver' => 'pgsql', 'url' => $url, 'charset' => 'utf8', 'prefix' => '']]);
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pages_permissions');
        $db = DB::connection();
        $schema = 'pages_permissions_'.bin2hex(random_bytes(6));
        $db->beginTransaction();

        try {
            $db->statement("CREATE SCHEMA {$schema}");
            $db->statement("SET LOCAL search_path TO {$schema}");
            $db->statement("DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'marketing_owl_app') THEN CREATE ROLE marketing_owl_app; END IF; END $$");
            $db->statement("GRANT USAGE ON SCHEMA {$schema} TO marketing_owl_app");
            foreach (['workspaces', 'brands', 'products', 'users', 'content_items'] as $table) {
                $db->statement("CREATE TABLE {$table} (id bigint PRIMARY KEY)");
                $db->table($table)->insert(['id' => 1]);
            }

            (require database_path('migrations/2026_10_16_100000_create_pages_tables.php'))->up();
            (require database_path('migrations/2026_10_25_100000_add_documents_to_pages.php'))->up();
            $db->statement('SET LOCAL ROLE marketing_owl_app');

            $store = $db->table('store_connections')->insertGetId([
                'workspace_id' => 1, 'name' => 'Store', 'driver' => 'woocommerce', 'site_url' => 'https://shop.example.com', 'status' => 'connected',
            ]);
            $page = $db->table('pages')->insertGetId([
                'workspace_id' => 1, 'product_id' => 1, 'store_connection_id' => $store, 'name' => 'Launch page', 'slug' => 'launch', 'headline' => 'Hello',
            ]);
            $db->table('page_sections')->insert(['page_id' => $page, 'position' => 1, 'kind' => 'message', 'title' => 'Message', 'content_item_id' => 1]);
            $version = $db->table('page_versions')->insertGetId(['page_id' => $page, 'version' => 1, 'snapshot' => '{}']);
            $attempt = $db->table('publish_attempts')->insertGetId(['page_id' => $page, 'page_version_id' => $version, 'store_connection_id' => $store, 'status' => 'publishing']);

            $this->assertSame(1, $db->table('publish_attempts')->where('id', $attempt)->update(['status' => 'live', 'warning' => 'Layout styles were removed.']));
            $this->assertSame(1, $db->table('pages')->where('id', $page)->update(['document' => '{"format":2,"sections":[]}', 'document_revision' => 2, 'template' => 'studio']));
            $generation = $db->table('page_generations')->insertGetId(['workspace_id' => 1, 'page_id' => $page, 'user_id' => 1, 'kind' => 'page', 'status' => 'running', 'base_revision' => 2, 'credit_cost' => 1]);
            $this->assertSame(1, $db->table('page_generations')->where('id', $generation)->update(['status' => 'completed', 'result_revision' => 3, 'cost_usd' => 0.02538]));
            $this->assertSame('0.02538', (string) $db->table('page_generations')->where('id', $generation)->value('cost_usd'));
            $this->assertSame(1, $db->table('pages')->where('id', $page)->update(['live_version' => 1, 'publish_state' => 'live']));
            $this->assertSame(1, $db->table('page_sections')->where('page_id', $page)->delete());
            $this->assertSame(1, $db->table('store_connections')->where('id', $store)->delete());
            $this->assertSame(1, $db->table('page_generations')->where('id', $generation)->delete());
            $this->assertNull($db->table('pages')->where('id', $page)->value('store_connection_id'));

            $db->statement('RESET ROLE');
            foreach (['store_connections', 'pages', 'page_sections', 'page_versions', 'publish_attempts', 'page_generations'] as $table) {
                $this->assertTrue((bool) $db->scalar("SELECT relrowsecurity FROM pg_class WHERE oid = '{$schema}.{$table}'::regclass"));
                $this->assertFalse((bool) $db->scalar("SELECT has_table_privilege('public', '{$schema}.{$table}', 'SELECT')"));
            }
        } finally {
            $db->rollBack();
            DB::purge('pages_permissions');
            DB::setDefaultConnection($previous);
        }
    }
}
