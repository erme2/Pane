<?php

namespace App\Console\Commands;

use App\Support\ReleaseMetadata;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class CacheReleaseMetadata extends Command
{
    protected $signature = 'release:cache
        {--release-version= : Release version, usually the SemVer tag without source edits}
        {--ref= : Git ref or branch name}
        {--commit= : Git commit SHA}
        {--built-at= : ISO-8601 build timestamp}
        {--cache-path= : Runtime cache file path; defaults to bootstrap/cache/pane-release.php}';

    protected $description = 'Cache non-secret Pane release metadata for smoke checks.';

    public function handle(ReleaseMetadata $releaseMetadata): int
    {
        $cachePath = $this->optionString('cache-path');
        if ($cachePath !== null) {
            Config::set('release.cache_path', $cachePath);
        }

        $metadata = $releaseMetadata->normalize($releaseMetadata->derivedMetadata(
            $this->optionString('release-version'),
            $this->optionString('ref'),
            $this->optionString('commit'),
            $this->optionString('built-at'),
        ));

        $releaseMetadata->writeCache($metadata);

        $this->info("Cached Pane release metadata for {$metadata['version']} ({$metadata['ref']}).");
        $this->line('Cache file: '.$releaseMetadata->cachePath());

        return self::SUCCESS;
    }

    private function optionString(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
