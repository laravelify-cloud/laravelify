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

    public function upload(BackupStorage $storage, string $file, string $key): void
    {
        $this->run($storage, ['copyto', $file, $this->remote($storage, $key), '--s3-no-check-bucket', '--s3-chunk-size', '16M', '--s3-upload-concurrency', '2']);
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
    private function run(BackupStorage $storage, array $arguments): void
    {
        $result = $this->runner->run(
            ['rclone', '--config', '/dev/null', '--retries', '3', '--low-level-retries', '5', '--contimeout', '30s', '--stats', '0', ...$arguments],
            null,
            $this->environment($storage),
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
