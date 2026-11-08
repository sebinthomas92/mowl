<?php

namespace App\Support;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Streams a private stored file, and explains a failure instead of a bare 404.
 *
 * Cloud disks answer "missing" for many reasons (a deleted object, a wrong bucket, a revoked key),
 * and the S3 client turns most of them into a plain false. When a file cannot be read, the reason
 * goes to the server log and, for signed-in workspace routes, to an X-Storage-Status header.
 */
class StoredFile
{
    /** @param  array<string, string>  $headers */
    public static function response(?string $disk, ?string $path, array $headers, bool $explain = false): StreamedResponse
    {
        $disk = $disk ?: (string) config('filesystems.default');
        $stream = null;
        $reason = null;
        try {
            $storage = Storage::disk($disk);
            if (! filled($path)) {
                $reason = 'no_path';
            } elseif (! $storage->exists($path)) {
                $reason = 'missing';
            } else {
                $stream = $storage->readStream($path);
                $reason = is_resource($stream) ? null : 'unreadable';
            }
        } catch (Throwable $exception) {
            $reason = 'error: '.class_basename($exception);
        }

        if ($reason !== null) {
            $status = self::diagnose($disk, (string) $path, $reason);
            error_log('stored_file_unavailable '.$status.' path='.$path);
            abort(404, headers: $explain ? ['X-Storage-Status' => $status] : []);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, $headers);
    }

    /** One line naming the disk, its driver and what the storage service said. No secrets. */
    public static function diagnose(string $disk, string $path, string $reason): string
    {
        $config = (array) config("filesystems.disks.{$disk}", []);
        $parts = ['disk='.$disk, 'driver='.($config['driver'] ?? 'unknown'), 'result='.$reason];
        try {
            $storage = Storage::disk($disk);
            if ($storage instanceof AwsS3V3Adapter) {
                $parts[] = 'bucket='.($config['bucket'] ?: 'unset');
                $parts[] = 'endpoint='.(parse_url((string) ($config['endpoint'] ?? ''), PHP_URL_HOST) ?: 'aws');
                $parts[] = 'key='.(filled($config['key'] ?? null) ? 'set' : 'unset');
                try {
                    $storage->getClient()->headObject(['Bucket' => $config['bucket'], 'Key' => $storage->path($path)]);
                    $parts[] = 'head=ok';
                } catch (Throwable $exception) {
                    $parts[] = 'head='.self::awsError($exception);
                }
            } elseif ($storage->getAdapter() instanceof GoogleCloudStorageAdapter) {
                $parts[] = 'bucket='.($config['bucket'] ?: 'unset');
                $parts[] = 'prefix='.(($config['path_prefix'] ?? '') ?: 'none');
            } elseif (($config['driver'] ?? null) === 'local') {
                $root = (string) ($config['root'] ?? '');
                $parts[] = 'root_exists='.(is_dir($root) ? 'yes' : 'no');
                $parts[] = 'ephemeral='.(str_starts_with($root, '/tmp') ? 'yes' : 'no');
            }
        } catch (Throwable $exception) {
            $parts[] = 'probe='.class_basename($exception);
        }

        return implode('; ', $parts);
    }

    private static function awsError(Throwable $exception): string
    {
        if (method_exists($exception, 'getAwsErrorCode')) {
            return trim(($exception->getStatusCode() ?? '').' '.($exception->getAwsErrorCode() ?? class_basename($exception)));
        }

        return class_basename($exception);
    }
}
