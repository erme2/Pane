<?php

namespace Tests\Unit\Testing;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class TestSuiteContractTest extends TestCase
{
    public function test_unit_tests_do_not_depend_on_database_backed_layers(): void
    {
        $root = dirname(__DIR__, 3);
        $failures = [];

        $patterns = [
            '/use\s+Illuminate\\\\Support\\\\Facades\\\\DB\s*;/' => 'imports the DB facade',
            '/\bDB::/' => 'calls the DB facade',
            '/use\s+App\\\\Helpers\\\\ActionHelper\s*;/' => 'uses ActionHelper, which resolves mapper-backed models',
            '/use\s+App\\\\Helpers\\\\CoreHelper\s*;/' => 'uses CoreHelper, which queries mapper tables',
            '/use\s+App\\\\Helpers\\\\MapperHelper\s*;/' => 'uses MapperHelper, which reads mapper fields',
            '/use\s+App\\\\Helpers\\\\ModelHelper\s*;/' => 'uses ModelHelper, which reads mapper fields',
            '/use\s+App\\\\Models\\\\Field\s*;/' => 'uses the Field model directly',
            '/extends\s+AbstractMapper/' => 'extends AbstractMapper, whose helpers read mapper tables',
        ];

        foreach ($this->phpFiles($root.'/tests/Unit') as $file) {
            if ($file->getPathname() === __FILE__) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            foreach ($patterns as $pattern => $reason) {
                if (preg_match($pattern, $contents) === 1) {
                    $failures[] = $this->relativePath($root, $file->getPathname()).' '.$reason;
                }
            }
        }

        self::assertSame([], $failures, 'Database-backed tests belong in tests/Feature, not tests/Unit.');
    }

    public function test_testing_environment_uses_hermetic_defaults(): void
    {
        $root = dirname(__DIR__, 3);
        $env = file_get_contents($root.'/.env.testing');
        $phpunit = file_get_contents($root.'/phpunit.xml');

        self::assertStringContainsString('DB_CONNECTION=sqlite', $env);
        self::assertStringContainsString('DB_DATABASE=database.sqlite', $env);
        self::assertStringContainsString('CACHE_DRIVER=array', $env);
        self::assertStringContainsString('LOG_CHANNEL=stderr', $env);
        self::assertMatchesRegularExpression('/<phpunit[^>]+cacheResult="false"/s', $phpunit);
    }

    public function test_testing_documentation_is_linked_and_explains_the_suite_contract(): void
    {
        $root = dirname(__DIR__, 3);
        $readme = file_get_contents($root.'/README.md');
        $doc = file_get_contents($root.'/docs/testing.md');

        self::assertStringContainsString('docs/testing.md', $readme);
        self::assertStringContainsString('tests/Unit', $doc);
        self::assertStringContainsString('tests/Feature', $doc);
        self::assertStringContainsString('php artisan test --env=testing --testsuite=Unit', $doc);
        self::assertStringContainsString('./bash/test.sh -o no -f no', $doc);
        self::assertStringContainsString('-r no', $doc);
        self::assertStringContainsString('DB_CONNECTION=sqlite', $doc);
        self::assertStringContainsString('database/database.sqlite', $doc);
        self::assertStringContainsString('CACHE_DRIVER=array', $doc);
        self::assertStringContainsString('LOG_CHANNEL=stderr', $doc);
        self::assertStringContainsString('Full Suite Lifecycle', $doc);
        self::assertStringContainsString('Writing Tests', $doc);
        self::assertStringContainsString('database/migrations/test', $doc);
    }

    /**
     * @return iterable<int, SplFileInfo>
     */
    private function phpFiles(string $directory): iterable
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
                yield $file;
            }
        }
    }

    private function relativePath(string $root, string $path): string
    {
        return ltrim(str_replace($root, '', $path), DIRECTORY_SEPARATOR);
    }
}
