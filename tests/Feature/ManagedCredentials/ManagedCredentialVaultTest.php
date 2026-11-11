<?php

namespace Tests\Feature\ManagedCredentials;

use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Services\ManagedCredentialVault;
use App\Services\OrganizationTenancyService;
use App\Support\PaneTable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

class ManagedCredentialVaultTest extends TestCase
{
    private const array CREDENTIALS = ['host' => 'db.internal', 'username' => 'app', 'password' => 'vault-secret-password'];

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        ManagedCredentialSecret::query()->delete();
        $this->configureKeys('key-v1');
        $this->organization = app(OrganizationTenancyService::class)->createOrganization(
            'Vault Workspace',
            'vault-workspace '.Str::uuid()
        );
    }

    public function test_stores_an_encrypted_record_and_reveals_it(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $secret = $vault->store($this->organization, self::CREDENTIALS, (string) Str::uuid());

        $raw = DB::table(PaneTable::name(PaneTable::MANAGED_CREDENTIAL_SECRETS))
            ->where('managed_credential_secret_id', $secret->managed_credential_secret_id)
            ->value('envelope');

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('vault-secret-password', $raw);
        $this->assertStringNotContainsString('vault-secret-password', $secret->toJson());
        $this->assertSame(self::CREDENTIALS, $vault->reveal($secret->fresh())->reveal());
    }

    public function test_an_envelope_moved_to_another_record_fails_authentication(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $first = $vault->store($this->organization, self::CREDENTIALS);
        $second = $vault->store($this->organization, ['password' => 'other']);

        $second->envelope = $first->envelope;
        $second->save();

        $this->expectException(DomainException::class);
        $vault->reveal($second->fresh());
    }

    public function test_rotate_command_re_encrypts_old_secrets_with_the_active_key(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $old = $vault->store($this->organization, self::CREDENTIALS);
        $this->configureKeys('key-v2');
        $current = $vault->store($this->organization, ['password' => 'already-current']);

        $this->command('managed-credentials:rotate --dry-run')
            ->expectsOutput('1 secret(s) need re-encryption with key [key-v2].')
            ->assertSuccessful();
        $this->assertSame('key-v1', $old->fresh()->envelope['key_id']);

        $this->command('managed-credentials:rotate')
            ->expectsOutput('Re-encrypted 1 secret(s) with key [key-v2]; 0 failed; 0 still to do.')
            ->assertSuccessful();

        $this->assertSame('key-v2', $old->fresh()->envelope['key_id']);
        $this->assertSame(self::CREDENTIALS, $vault->reveal($old->fresh())->reveal());
        $this->assertSame($current->envelope, $current->fresh()->envelope);
    }

    public function test_rotate_command_pages_through_more_secrets_than_one_chunk(): void
    {
        $vault = app(ManagedCredentialVault::class);
        for ($i = 0; $i < 105; $i++) {
            $vault->store($this->organization, self::CREDENTIALS);
        }
        $this->configureKeys('key-v2');

        $this->command('managed-credentials:rotate --dry-run')
            ->expectsOutput('105 secret(s) need re-encryption with key [key-v2].')
            ->assertSuccessful();

        $this->command('managed-credentials:rotate --limit=150')
            ->expectsOutput('Re-encrypted 105 secret(s) with key [key-v2]; 0 failed; 0 still to do.')
            ->assertSuccessful();
    }

    public function test_rotate_command_respects_the_limit(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $vault->store($this->organization, self::CREDENTIALS);
        $vault->store($this->organization, self::CREDENTIALS);
        $this->configureKeys('key-v2');

        $this->command('managed-credentials:rotate --limit=1')
            ->expectsOutput('Re-encrypted 1 secret(s) with key [key-v2]; 0 failed; 1 still to do.')
            ->assertSuccessful();
    }

    public function test_rotate_command_fails_without_an_active_key(): void
    {
        config()->set('services.managed_credentials.active_key_id', 'missing-key');

        $this->command('managed-credentials:rotate')->assertFailed();
    }

    public function test_rotate_command_reports_secrets_whose_key_is_gone(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $vault->store($this->organization, self::CREDENTIALS);
        $this->configureKeys('key-v2', withOld: false);

        $this->command('managed-credentials:rotate')
            ->expectsOutput('Re-encrypted 0 secret(s) with key [key-v2]; 1 failed; 0 still to do.')
            ->assertFailed();
    }

    public function test_check_command_and_health_summary(): void
    {
        $vault = app(ManagedCredentialVault::class);
        $vault->store($this->organization, self::CREDENTIALS);
        $this->configureKeys('key-v2');
        $vault->store($this->organization, self::CREDENTIALS);

        $this->assertSame(
            ['active_key' => 'ok', 'secrets' => 2, 'needing_rotation' => 1, 'missing_key' => 0],
            $vault->health()
        );
        $this->command('managed-credentials:check')->assertSuccessful();

        $this->configureKeys('key-v2', withOld: false);
        $this->assertSame(1, $vault->health()['missing_key']);
        $this->command('managed-credentials:check')->assertFailed();

        config()->set('services.managed_credentials.keys', null);
        $this->assertSame('unavailable', $vault->health()['active_key']);
        $this->command('managed-credentials:check')->assertFailed();
    }

    public function test_status_endpoint_reports_key_health_without_secrets(): void
    {
        config(['app.status_password' => 'status-secret']);
        config()->set('services.managed_credentials.keys', null);

        $this
            ->withHeader('Authorization', 'Basic '.base64_encode('pane:status-secret'))
            ->getJson('/status')
            ->assertOk()
            ->assertJsonPath('data.managed_credentials.active_key', 'unavailable')
            ->assertJsonPath('data.managed_credentials.secrets', 0);
    }

    private function command(string $command): PendingCommand
    {
        $pending = $this->artisan($command);
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    private function configureKeys(string $active, bool $withOld = true): void
    {
        $keys = [
            'key-v1' => 'base64:'.base64_encode(str_repeat('d', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
            'key-v2' => 'base64:'.base64_encode(str_repeat('e', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ];
        if (! $withOld) {
            unset($keys['key-v1']);
        }

        config()->set('services.managed_credentials.active_key_id', $active);
        config()->set('services.managed_credentials.keys', json_encode($keys, JSON_THROW_ON_ERROR));
    }
}
