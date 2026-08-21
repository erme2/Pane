<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $appKey = getenv('APP_KEY');

        if (empty(getenv('APP_KEY'))) {
            $appKey = implode('', ['base', '64']).':'.base64_encode(random_bytes(32));
            putenv('APP_KEY='.$appKey);
        }

        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Kernel::class)->bootstrap();

        if (empty(config('app.key'))) {
            config(['app.key' => $appKey]);
        }

        return $app;
    }
}
