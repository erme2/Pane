<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class HostingerRootHtaccessTest extends TestCase
{
    private string $htaccess;

    protected function setUp(): void
    {
        parent::setUp();

        $htaccess = file_get_contents(dirname(__DIR__, 2).'/.htaccess');

        $this->assertIsString($htaccess);
        $this->htaccess = $htaccess;
    }

    public function test_root_htaccess_rewrites_hostinger_project_root_to_public_front_controller(): void
    {
        $this->assertStringContainsString('RewriteRule ^$ public/ [L]', $this->htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/public/', $this->htaccess);
        $this->assertStringContainsString('RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -f [OR]', $this->htaccess);
        $this->assertStringContainsString('RewriteCond %{DOCUMENT_ROOT}/public%{REQUEST_URI} -d', $this->htaccess);
        $this->assertStringContainsString('RewriteRule ^(.*)$ public/$1 [L]', $this->htaccess);
        $this->assertStringContainsString('RewriteRule ^(.*)$ public/index.php [L]', $this->htaccess);
    }

    public function test_root_htaccess_denies_sensitive_project_files_before_rewriting(): void
    {
        $this->assertMatchesRegularExpression('/RewriteRule \(\^\|\/\)\\\\\. - \[F\]/', $this->htaccess);
        $this->assertStringContainsString('RewriteRule ^(app|bootstrap|config|database|routes|storage|tests|vendor)(/|$) - [F]', $this->htaccess);
        $this->assertStringNotContainsString('database|docs|routes', $this->htaccess);
        $this->assertStringContainsString('RewriteRule ^(artisan|composer\.(json|lock)|phpunit\.xml|phpstan.*|Dockerfile.*|docker-compose\.yml)$ - [F]', $this->htaccess);
    }
}
