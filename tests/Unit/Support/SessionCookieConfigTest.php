<?php

namespace Tests\Unit\Support;

use App\Support\SessionCookieConfig;
use Tests\TestCase;

class SessionCookieConfigTest extends TestCase
{
    public function test_secure_cookie_defaults_to_false_for_local_and_testing(): void
    {
        $this->assertFalse(SessionCookieConfig::secure(null, 'local'));
        $this->assertFalse(SessionCookieConfig::secure(null, 'testing'));
    }

    public function test_secure_cookie_defaults_to_true_for_non_local_environments(): void
    {
        $this->assertTrue(SessionCookieConfig::secure(null, 'production'));
        $this->assertTrue(SessionCookieConfig::secure(null, 'staging'));
    }

    public function test_explicit_secure_cookie_config_is_respected(): void
    {
        $this->assertTrue(SessionCookieConfig::secure(true, 'local'));
        $this->assertFalse(SessionCookieConfig::secure(false, 'production'));
    }
}
