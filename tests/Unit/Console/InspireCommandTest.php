<?php

namespace Tests\Unit\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class InspireCommandTest extends TestCase
{
    public function test_inspire_command_writes_to_artisan_output(): void
    {
        Artisan::call('inspire');

        self::assertNotSame('', trim(Artisan::output()));
    }
}
