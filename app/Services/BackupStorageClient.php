<?php

namespace App\Services;

use App\Models\BackupStorage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Talks to an S3-compatible bucket through rclone, so files of any size are uploaded in parts,
 * and AWS S3, DigitalOcean Spaces, Cloudflare R2, Backblaze B2, Wasabi, and MinIO all work.
 * The keys travel in the environment of the one command, never on its command line.
 */
class BackupStorageClient
{
    public function __construct(private CommandRunner $runner) {}

    /**
     * Upload a small file, read it back by deleting it, and say what went wrong if it did not work.
     */
    public function test(BackupStorage $storage): void
    {
        $probe = storage_path('app/backup-probe-'.Str::random(10).'.txt');
        file_put_contents($probe, 'Laravelify storage check '.now()->toIso8601String());

        try {
            $key = '.laravelify-check-'.Str::random(8).'.txt';
            $this->upload($storage, $probe, $key);
            $this->delete($storage, $key);
        } finally {
            @unlink($probe);
        }
    }

    /**
     * @param  (callable(int): void)|null  $progress  Called with the percent uploaded, about every second.
     */
    public function upload(BackupStorage $storage, string $file, string $key, ?callable $progress = null): void
    {
        $arguments = ['copyto', $file, $this->remote($storage, $key), '--s3-no-check-bucket', '--s3-chunk-size', '16M', '--s3-upload-concurrency', '2'];

        if ($progress === null) {
            $this->run($storage, $arguments);

            return;
        }

        // One stats line a second, like "12.5 MiB / 76.6 MiB, 16%, 2.1 MiB/s, ETA 30s".
        $this->run($storage, [...$arguments, '--stats', '1s', '--stats-one-line', '--stats-log-level', 'NOTICE'], function (string $output) use ($progress): void {
            if (preg_match_all('/,\s*(\d{1,3})%,/', $output, $matches) && $matches[1] !== []) {
                $progress(min(100, (int) end($matches[1])));
            }
        });
    }

    /**
     * A link that downloads one backup without the keys, for $seconds (S3 allows up to 7 days).
     * Signed here with AWS Signature V4, so it works with every S3-compatible provider.
     */
    public function temporaryUrl(BackupStorage $storage, string $key, int $seconds = 86400): string
    {
        $seconds = max(60, min(604800, $seconds));
        $path = implode('/', array_filter([$storage->prefix(), ltrim($key, '/')], fn (string $part) => $part !== ''));
        $region = $storage->region ?: 'us-east-1';

        if (filled($storage->endpoint)) {
            $base = $this->normalizeEndpoint((string) $storage->endpoint);
            $uri = '/'.rawurlencode($storage->bucket).'/'.$this->encodePath($path);
        } elseif (str_contains($storage->bucket, '.')) {
            $base = 'https://s3.'.$region.'.amazonaws.com';
            $uri = '/'.rawurlencode($storage->bucket).'/'.$this->encodePath($path);
        } else {
            $base = 'https://'.$storage->bucket.($region === 'us-east-1' ? '.s3.amazonaws.com' : '.s3.'.$region.'.amazonaws.com');
            $uri = '/'.$this->encodePath($path);
        }

        $parts = parse_url($base);
        $host = $parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $basePath = rtrim($parts['path'] ?? '', '/');
        $now = now()->utc();
        $date = $now->format('Ymd');
        $scope = $date.'/'.$region.'/s3/aws4_request';

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $storage->access_key.'/'.$scope,
            'X-Amz-Date' => $now->format('Ymd\THis\Z'),
            'X-Amz-Expires' => (string) $seconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($query);
        $canonicalQuery = implode('&', array_map(fn (string $name, string $value) => rawurlencode($name).'='.rawurlencode($value), array_keys($query), $query));
        $canonical = implode("\n", ['GET', $basePath.$uri, $canonicalQuery, 'host:'.$host, '', 'host', 'UNSIGNED-PAYLOAD']);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $query['X-Amz-Date'], $scope, hash('sha256', $canonical)]);

        $key = 'AWS4'.$storage->secret_key;

        foreach ([$date, $region, 's3', 'aws4_request'] as $part) {
            $key = hash_hmac('sha256', $part, $key, true);
        }

        return $parts['scheme'].'://'.$host.$basePath.$uri.'?'.$canonicalQuery.'&X-Amz-Signature='.hash_hmac('sha256', $toSign, $key);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    public function download(BackupStorage $storage, string $key, string $file): void
    {
        $this->run($storage, ['copyto', $this->remote($storage, $key), $file, '--s3-no-check-bucket']);
    }

    public function delete(BackupStorage $storage, string $key): void
    {
        $this->run($storage, ['deletefile', $this->remote($storage, $key), '--s3-no-check-bucket']);
    }

    /**
     * lv:bucket/folder/key. "lv" is the remote the environment below defines.
     */
    public function remote(BackupStorage $storage, string $key): string
    {
        $parts = array_filter([$storage->bucket, $storage->prefix(), ltrim($key, '/')], fn (string $part) => $part !== '');

        return 'lv:'.implode('/', $parts);
    }

    /**
     * @return array<string, string>
     */
    public function environment(BackupStorage $storage): array
    {
        $custom = filled($storage->endpoint);

        return array_filter([
            'RCLONE_CONFIG_LV_TYPE' => 's3',
            'RCLONE_CONFIG_LV_PROVIDER' => $custom ? 'Other' : 'AWS',
            'RCLONE_CONFIG_LV_ENV_AUTH' => 'false',
            'RCLONE_CONFIG_LV_ACCESS_KEY_ID' => $storage->access_key,
            'RCLONE_CONFIG_LV_SECRET_ACCESS_KEY' => $storage->secret_key,
            'RCLONE_CONFIG_LV_REGION' => $storage->region ?: 'us-east-1',
            'RCLONE_CONFIG_LV_ENDPOINT' => $custom ? $this->normalizeEndpoint((string) $storage->endpoint) : '',
        ], fn (string $value) => $value !== '');
    }

    /**
     * Accepts "nyc3.digitaloceanspaces.com" and "https://nyc3.digitaloceanspaces.com/".
     */
    public function normalizeEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);

        if (! preg_match('#^https?://#i', $endpoint)) {
            $endpoint = 'https://'.$endpoint;
        }

        return rtrim($endpoint, '/');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function run(BackupStorage $storage, array $arguments, ?callable $onOutput = null): void
    {
        $result = $this->runner->run(
            ['rclone', '--config', '/dev/null', '--retries', '3', '--low-level-retries', '5', '--contimeout', '30s', '--stats', '0', ...$arguments],
            null,
            $this->environment($storage),
            $onOutput,
        );

        if ($result->ok) {
            return;
        }

        throw new RuntimeException($this->explain($result->output, $storage));
    }

    /**
     * The last useful line of rclone's output, with a hint for the common mistakes.
     */
    private function explain(string $output, BackupStorage $storage): string
    {
        $keys = array_filter([(string) $storage->secret_key, (string) $storage->access_key], fn (string $key) => strlen($key) >= 8);
        $clean = $keys === [] ? $output : str_replace($keys, '***', $output);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $clean))));
        $last = $lines === [] ? 'rclone failed.' : end($lines);

        $hint = match (true) {
            str_contains($clean, 'rclone: command not found'), str_contains($clean, 'executable file not found') => 'rclone is not installed on this server. Run the Laravelify update, or: sudo apt-get install -y rclone',
            str_contains($clean, 'InvalidAccessKeyId'), str_contains($clean, 'SignatureDoesNotMatch') => 'The access key or secret key is wrong.',
            str_contains($clean, 'AccessDenied') => 'The keys cannot write to this bucket. Give them read, write, and delete access.',
            str_contains($clean, 'NoSuchBucket') => 'The bucket does not exist. Check the bucket name and the region.',
            str_contains($clean, 'no such host'), str_contains($clean, 'dial tcp') => 'The endpoint cannot be reached. Check the endpoint address.',
            default => null,
        };

        return $hint ? $hint.' ('.Str::limit($last, 160).')' : Str::limit($last, 300);
    }
}
