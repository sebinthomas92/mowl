<?php

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class ServerlessBootstrapTest extends TestCase
{
    public function test_clean_serverless_bootstrap_uses_configured_writable_storage(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = $root.'/storage/framework/testing/serverless-'.bin2hex(random_bytes(8));

        try {
            $process = $this->bootstrap($root, $storage);
            $process->mustRun();
            $this->assertTrue(json_validate($process->getOutput()), 'Invalid bootstrap output: '.substr($process->getOutput(), 0, 1000));
            $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertTrue($result['getenv_only']);
            $this->assertSame($storage, $result['storage']);
            $this->assertSame($storage, $result['binding']);
            $this->assertSame($storage.'/framework/cache/data', $result['cache']);
            $this->assertSame($storage.'/framework/sessions', $result['sessions']);
            $this->assertSame($storage.'/framework/views', $result['views']);
            $this->assertSame($storage.'/logs/laravel.log', $result['log']);
            $this->assertSame($storage.'/framework/cache/packages.php', $result['packages']);
            $this->assertSame($storage.'/framework/cache/services.php', $result['services']);
            $this->assertTrue($result['login_rendered']);
            $this->assertTrue($result['cache_written']);
            $this->assertTrue($result['session_written']);
            $this->assertTrue($result['view_written']);
            $this->assertTrue($result['log_written']);
        } finally {
            (new Filesystem)->deleteDirectory($storage);
        }
    }

    public function test_serverless_pre_rendered_maintenance_uses_selected_storage(): void
    {
        $root = dirname(__DIR__, 2);
        $storage = $root.'/storage/framework/testing/serverless-'.bin2hex(random_bytes(8));
        $files = new Filesystem;

        try {
            $files->makeDirectory($storage.'/framework', 0775, true);
            $files->put($storage.'/framework/maintenance.php', '<?php http_response_code(503); echo "isolated maintenance: ".http_response_code(); exit;');
            $process = $this->bootstrap($root, $storage);
            $process->mustRun();

            $this->assertSame('isolated maintenance: 503', $process->getOutput());
            $this->assertFileDoesNotExist($storage.'/framework/cache/packages.php');
        } finally {
            $files->deleteDirectory($storage);
        }
    }

    private function bootstrap(string $root, string $storage): Process
    {
        // No application/provider credentials or database settings enter this process.
        $env = array_fill_keys(array_keys(getenv()), false);
        $env = array_merge($env, [
            'APP_ENV' => 'testing',
            'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('s', 32)),
            'APP_URL' => 'http://localhost',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'CACHE_STORE' => 'file',
            'SESSION_DRIVER' => 'file',
            'LOG_CHANNEL' => 'single',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'LARAVEL_STORAGE_PATH' => $storage,
        ]);

        return new Process([PHP_BINARY, '-d', 'variables_order=GPC', $root.'/tests/Fixtures/serverless-bootstrap.php'], $root, $env);
    }
}
