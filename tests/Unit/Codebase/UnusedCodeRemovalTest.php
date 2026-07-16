<?php

namespace Tests\Unit\Codebase;

use PHPUnit\Framework\TestCase;

class UnusedCodeRemovalTest extends TestCase
{
    public function test_laravel_starter_and_test_fixture_artifacts_stay_removed(): void
    {
        $root = dirname(__DIR__, 3);

        $removedPaths = [
            'app/Actions/TestAction.php',
            'app/Providers/BroadcastServiceProvider.php',
            'config/broadcasting.php',
            'app/Stories/TestStory.php',
            'config/sanctum.php',
            'database/useful/validation_fields.sql',
            'resources/css/app.css',
            'resources/js/app.js',
            'resources/js/bootstrap.js',
            'routes/api.php',
            'routes/channels.php',
            'routes/console.php',
            'tests/Unit/Console/InspireCommandTest.php',
            'tests/Unit/ExampleTest.php',
        ];

        foreach ($removedPaths as $path) {
            self::assertFileDoesNotExist($root.'/'.$path, $path.' should not be reintroduced without a real Pane use case.');
        }
    }

    public function test_framework_configuration_no_longer_references_removed_starter_artifacts(): void
    {
        $root = dirname(__DIR__, 3);

        $composerJson = file_get_contents($root.'/composer.json');
        $composerLock = file_get_contents($root.'/composer.lock');
        $corsConfig = file_get_contents($root.'/config/cors.php');
        $routeServiceProvider = file_get_contents($root.'/app/Providers/RouteServiceProvider.php');
        $consoleKernel = file_get_contents($root.'/app/Console/Kernel.php');
        $appConfig = file_get_contents($root.'/config/app.php');
        $envExample = file_get_contents($root.'/.env.example');
        $envTesting = file_get_contents($root.'/.env.testing');
        $envDocker = file_get_contents($root.'/.env.docker');
        $environmentDocs = file_get_contents($root.'/docs/environment.md');
        $httpKernel = file_get_contents($root.'/app/Http/Kernel.php');
        $userModel = file_get_contents($root.'/app/Models/User.php');

        self::assertStringNotContainsString('laravel/sanctum', $composerJson);
        self::assertStringNotContainsString('laravel/sanctum', $composerLock);
        self::assertStringNotContainsString('sanctum/csrf-cookie', $corsConfig);
        self::assertStringNotContainsString("'api/*'", $corsConfig);
        self::assertStringNotContainsString('routes/api.php', $routeServiceProvider);
        self::assertStringNotContainsString("RateLimiter::for('api'", $routeServiceProvider);
        self::assertStringNotContainsString('routes/console.php', $consoleKernel);
        self::assertStringNotContainsString('inspire', $consoleKernel);
        self::assertStringNotContainsString('BroadcastServiceProvider', $appConfig);
        self::assertStringNotContainsString('BROADCAST_DRIVER', $envExample);
        self::assertStringNotContainsString('BROADCAST_DRIVER', $envTesting);
        self::assertStringNotContainsString('BROADCAST_DRIVER', $envDocker);
        self::assertStringNotContainsString('Broadcast', $environmentDocs);
        self::assertStringNotContainsString('Sanctum', $httpKernel);
        self::assertStringNotContainsString('HasApiTokens', $userModel);
        self::assertStringNotContainsString('Laravel\\Sanctum', $userModel);
    }
}
