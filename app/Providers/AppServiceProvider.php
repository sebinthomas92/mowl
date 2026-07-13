<?php

namespace App\Providers;

use App\Services\GoogleCloudAccessTokenProvider;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Filesystem\FilesystemAdapter as LaravelFilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use League\Flysystem\Filesystem;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\GoogleCloudStorage\UniformBucketLevelAccessVisibility;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GoogleCloudAccessTokenProvider::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Storage::extend('gcs', function ($app, array $config): LaravelFilesystemAdapter {
            if (! ($config['project_id'] ?? null) || ! ($config['bucket'] ?? null)) {
                throw new \RuntimeException('Google Cloud Storage project and bucket must be configured.');
            }

            $client = new StorageClient([
                'projectId' => $config['project_id'],
                'credentialsFetcher' => $app->make(GoogleCloudAccessTokenProvider::class),
            ]);
            $adapter = new GoogleCloudStorageAdapter(
                $client->bucket($config['bucket']),
                $config['path_prefix'] ?? '',
                new UniformBucketLevelAccessVisibility,
            );

            return new LaravelFilesystemAdapter(new Filesystem($adapter), $adapter, $config);
        });
    }
}
