<?php

namespace Tests\Unit\Security;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;

class GuzzleDependencyTest extends TestCase
{
    public function test_guzzle_packages_are_above_known_advisory_versions(): void
    {
        $minimumVersions = [
            'guzzlehttp/guzzle' => '7.12.1',
            'guzzlehttp/psr7' => '2.12.1',
        ];

        foreach ($minimumVersions as $package => $minimumVersion) {
            $installedVersion = InstalledVersions::getVersion($package);

            $this->assertNotNull($installedVersion, "{$package} should be installed.");
            $this->assertGreaterThanOrEqual(
                0,
                version_compare($installedVersion, $minimumVersion),
                "{$package} should be at least {$minimumVersion}; {$installedVersion} is installed."
            );
        }
    }
}
