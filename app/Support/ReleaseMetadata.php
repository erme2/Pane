<?php

namespace App\Support;

class ReleaseMetadata
{
    /**
     * @return array{application: string, version: string, ref: string, commit: string|null, built_at: string|null}
     */
    public function current(): array
    {
        return $this->normalize($this->cachedMetadata() ?? $this->derivedMetadata());
    }

    /**
     * @return array{application?: mixed, version?: mixed, ref?: mixed, commit?: mixed, built_at?: mixed}
     */
    public function derivedMetadata(
        ?string $version = null,
        ?string $ref = null,
        ?string $commit = null,
        ?string $builtAt = null,
    ): array {
        return [
            'application' => config('app.name', 'pane'),
            'version' => $this->metadataValue($version)
                ?? $this->metadataValue($this->environment('GITHUB_REF_NAME'))
                ?? $this->gitValue('describe --tags --exact-match')
                ?? 'dev',
            'ref' => $this->metadataValue($ref)
                ?? $this->metadataValue($this->environment('GITHUB_REF_NAME'))
                ?? $this->gitValue('branch --show-current')
                ?? 'local',
            'commit' => $this->metadataValue($commit)
                ?? $this->metadataValue($this->environment('GITHUB_SHA'))
                ?? $this->gitValue('rev-parse HEAD'),
            'built_at' => $this->metadataValue($builtAt),
        ];
    }

    /**
     * @param  array{application?: mixed, version?: mixed, ref?: mixed, commit?: mixed, built_at?: mixed}  $metadata
     * @return array{application: string, version: string, ref: string, commit: string|null, built_at: string|null}
     */
    public function normalize(array $metadata): array
    {
        return [
            'application' => $this->metadataValue($metadata['application'] ?? null) ?? 'pane',
            'version' => $this->metadataValue($metadata['version'] ?? null) ?? 'dev',
            'ref' => $this->metadataValue($metadata['ref'] ?? null) ?? 'local',
            'commit' => $this->metadataValue($metadata['commit'] ?? null),
            'built_at' => $this->metadataValue($metadata['built_at'] ?? null),
        ];
    }

    public function cachePath(): string
    {
        $path = config('release.cache_path');

        return is_string($path) && trim($path) !== ''
            ? $path
            : base_path('bootstrap/cache/pane-release.php');
    }

    /**
     * @param  array{application: string, version: string, ref: string, commit: string|null, built_at: string|null}  $metadata
     */
    public function writeCache(array $metadata): void
    {
        $written = file_put_contents(
            $this->cachePath(),
            '<?php return '.var_export($metadata, true).';'.PHP_EOL,
            LOCK_EX,
        );

        if ($written === false) {
            throw new \RuntimeException('Unable to write Pane release metadata cache.');
        }
    }

    public function clearCache(): void
    {
        if (file_exists($this->cachePath())) {
            unlink($this->cachePath());
        }
    }

    /**
     * @return array{application?: mixed, version?: mixed, ref?: mixed, commit?: mixed, built_at?: mixed}|null
     */
    private function cachedMetadata(): ?array
    {
        if (! file_exists($this->cachePath())) {
            return null;
        }

        $metadata = require $this->cachePath();

        return is_array($metadata) ? $metadata : null;
    }

    private function gitValue(string $arguments): ?string
    {
        if (! is_dir(base_path('.git')) || ! function_exists('exec')) {
            return null;
        }

        $output = [];
        $status = 0;
        exec('git -C '.escapeshellarg(base_path()).' '.$arguments.' 2>/dev/null', $output, $status);

        if ($status !== 0 || $output === []) {
            return null;
        }

        $first = reset($output);

        return $this->metadataValue($first);
    }

    private function environment(string $key): ?string
    {
        $value = getenv($key);

        return is_string($value) ? $value : null;
    }

    private function metadataValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
