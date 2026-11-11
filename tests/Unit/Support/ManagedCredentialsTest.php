<?php

namespace Tests\Unit\Support;

use App\Support\ManagedCredentials;
use LogicException;
use PHPUnit\Framework\TestCase;

class ManagedCredentialsTest extends TestCase
{
    private function credentials(): ManagedCredentials
    {
        return new ManagedCredentials(['username' => 'app', 'password' => 'plain-secret']);
    }

    public function test_values_are_read_explicitly(): void
    {
        $credentials = $this->credentials();

        self::assertSame('plain-secret', $credentials->get('password'));
        self::assertNull($credentials->get('missing'));
        self::assertSame(['username' => 'app', 'password' => 'plain-secret'], $credentials->reveal());
    }

    public function test_dumps_strings_and_json_are_redacted(): void
    {
        $credentials = $this->credentials();

        self::assertStringNotContainsString('plain-secret', print_r($credentials, true));
        self::assertStringNotContainsString('plain-secret', var_export($credentials->__debugInfo(), true));
        self::assertSame('[redacted]', (string) $credentials);
        self::assertSame('{"credentials":"[redacted]"}', json_encode(['credentials' => $credentials]));
    }

    public function test_var_export_and_array_casts_do_not_expose_plaintext(): void
    {
        $credentials = $this->credentials();

        self::assertStringNotContainsString('plain-secret', var_export($credentials, true));
        self::assertStringNotContainsString('plain-secret', var_export((array) $credentials, true));
        self::assertSame([], get_object_vars($credentials));
    }

    public function test_cloning_is_refused(): void
    {
        $this->expectException(LogicException::class);

        clone $this->credentials();
    }

    public function test_serialization_is_refused(): void
    {
        $this->expectException(LogicException::class);

        serialize($this->credentials());
    }

    public function test_plaintext_is_hidden_from_stack_traces(): void
    {
        try {
            (function (ManagedCredentials $credentials): never {
                throw new \RuntimeException('boom');
            })($this->credentials());
        } catch (\RuntimeException $exception) {
            self::assertStringNotContainsString('plain-secret', $exception->getTraceAsString());
            self::assertStringNotContainsString('plain-secret', print_r($exception->getTrace(), true));
        }
    }
}
