<?php

namespace Tests\Feature\Settings;

use App\Models\AuditEvent;
use App\Models\SettingDefault;
use App\Models\SettingOverride;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\SettingDefinition;
use App\Support\SettingsRegistry;
use DomainException;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    /**
     * @var array<string, array{value: mixed, default_version: int}>|null
     */
    private static ?array $migratedDefaults = null;

    private SettingsService $settings;

    protected function setUp(): void
    {
        parent::setUp();

        SettingOverride::query()->delete();
        $this->restoreMigratedDefaults();

        $this->settings = app(SettingsService::class);
    }

    public function test_resolution_prefers_the_installation_override_then_the_versioned_default(): void
    {
        $administrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        $this->assertSame(
            86_400,
            $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
        );

        $override = $this->settings->setInstallationOverride(
            $administrator,
            SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
            172_800
        );

        $this->assertSame(1, $override->default_version);
        $this->assertSame(
            172_800,
            $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
        );
    }

    public function test_invalid_settings_are_rejected(): void
    {
        $administrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        try {
            $this->settings->resolve('missing.setting');
            $this->fail('Expected unregistered setting lookup to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unregistered setting [missing.setting].', $exception->getMessage());
        }

        try {
            $this->settings->setInstallationOverride(
                $administrator,
                SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
                '86400'
            );
            $this->fail('Expected wrong-type setting update to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Setting [pane_admin.invitation_expiry_seconds] requires an integer value.',
                $exception->getMessage()
            );
        }

        try {
            $this->settings->setInstallationOverride(
                $administrator,
                SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
                300
            );
            $this->fail('Expected out-of-bounds setting update to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Setting [pane_admin.invitation_expiry_seconds] must be at least 3600.',
                $exception->getMessage()
            );
        }
    }

    public function test_users_cannot_change_installation_settings(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);

        $this->expectException(DomainException::class);
        $this->settings->setInstallationOverride(
            $user,
            SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
            86_400
        );
    }

    public function test_defaults_are_resolved_from_migrated_versioned_state(): void
    {
        $default = SettingDefault::query()
            ->where('setting_key', SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
            ->firstOrFail();

        $this->assertSame(1, $default->default_version);
        $this->assertSame(
            86_400,
            $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
        );

        $default->update([
            'value' => 172_800,
            'default_version' => 2,
        ]);

        $this->assertSame(
            172_800,
            $this->settings->resolve(SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
        );
    }

    public function test_registered_defaults_are_versioned_and_setting_changes_are_audited(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        foreach ($this->settings->registeredSettings() as $definition) {
            $this->assertInstanceOf(SettingDefinition::class, $definition);
            $this->assertGreaterThan(0, $definition->defaultVersion);
            $this->assertTrue(
                SettingDefault::query()
                    ->where('setting_key', $definition->key)
                    ->where('default_version', $definition->defaultVersion)
                    ->exists()
            );
        }

        $override = $this->settings->setInstallationOverride(
            $paneAdministrator,
            SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
            172_800
        );

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', 'settings.override.update')
                ->where('real_actor_user_id', $paneAdministrator->getKey())
                ->whereJsonContains('resource_ids->setting_key', SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS)
                ->whereJsonContains('resource_ids->setting_override_id', $override->getKey())
                ->exists()
        );
    }

    private function restoreMigratedDefaults(): void
    {
        if (self::$migratedDefaults === null) {
            self::$migratedDefaults = SettingDefault::query()
                ->get()
                ->mapWithKeys(static fn (SettingDefault $default): array => [
                    $default->setting_key => [
                        'value' => $default->value,
                        'default_version' => $default->default_version,
                    ],
                ])
                ->all();
        }

        SettingDefault::query()->delete();

        foreach (self::$migratedDefaults as $settingKey => $default) {
            SettingDefault::query()->create([
                'setting_key' => $settingKey,
                'value' => $default['value'],
                'default_version' => $default['default_version'],
            ]);
        }
    }
}
