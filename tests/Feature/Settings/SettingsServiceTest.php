<?php

namespace Tests\Feature\Settings;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\SettingDefault;
use App\Models\SettingOverride;
use App\Models\User;
use App\Services\OrganizationTenancyService;
use App\Services\SettingsService;
use App\Support\SettingDefinition;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    /**
     * @var array<string, array{value: mixed, default_version: int}>|null
     */
    private static ?array $migratedDefaults = null;

    private SettingsService $settings;

    private OrganizationTenancyService $tenancy;

    protected function setUp(): void
    {
        parent::setUp();

        SettingOverride::query()->delete();
        $this->restoreMigratedDefaults();

        $this->settings = app(SettingsService::class);
        $this->tenancy = app(OrganizationTenancyService::class);
    }

    public function test_resolution_prefers_organization_override_then_installation_override_then_versioned_default(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Settings Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $this->assertSame(
            604_800,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS, $organization)
        );

        $installationOverride = $this->settings->setInstallationOverride(
            $paneAdministrator,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            172_800
        );

        $this->assertSame(1, $installationOverride->default_version);
        $this->assertSame(
            172_800,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS, $organization)
        );

        $organizationOverride = $this->settings->setOrganizationOverride(
            $organizationAdministrator,
            $organization,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            86_400
        );

        $this->assertSame(1, $organizationOverride->default_version);
        $this->assertSame(
            86_400,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS, $organization)
        );
    }

    public function test_invalid_settings_and_scope_are_rejected(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Rejected Settings Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        try {
            $this->settings->resolve('missing.setting');
            $this->fail('Expected unregistered setting lookup to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame('Unregistered setting [missing.setting].', $exception->getMessage());
        }

        try {
            $this->settings->setInstallationOverride(
                $paneAdministrator,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
                '604800'
            );
            $this->fail('Expected wrong-type setting update to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Setting [organization.invitation_expiry_seconds] requires an integer value.',
                $exception->getMessage()
            );
        }

        try {
            $this->settings->setOrganizationOverride(
                $organizationAdministrator,
                $organization,
                SettingsRegistry::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
                86_400
            );
            $this->fail('Expected incorrectly scoped setting update to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Setting [pane_admin.invitation_expiry_seconds] does not allow [organization] overrides.',
                $exception->getMessage()
            );
        }

        try {
            $this->settings->setOrganizationOverride(
                $organizationAdministrator,
                $organization,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
                300
            );
            $this->fail('Expected out-of-bounds setting update to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Setting [organization.invitation_expiry_seconds] must be at least 3600.',
                $exception->getMessage()
            );
        }
    }

    public function test_organization_administrators_cannot_change_installation_settings(): void
    {
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Org Admin Settings Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $this->expectException(DomainException::class);
        $this->settings->setInstallationOverride(
            $organizationAdministrator,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            86_400
        );
    }

    public function test_pane_admin_controls_bounds_for_organization_overrides(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Bounded Settings Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $this->settings->setInstallationOverride(
            $paneAdministrator,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS,
            7200
        );
        $this->settings->setInstallationOverride(
            $paneAdministrator,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS,
            172_800
        );

        $this->settings->setOrganizationOverride(
            $organizationAdministrator,
            $organization,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            86_400
        );

        $this->assertSame(
            86_400,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS, $organization)
        );

        $this->expectException(InvalidArgumentException::class);
        $this->settings->setOrganizationOverride(
            $organizationAdministrator,
            $organization,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            259_200
        );
    }

    public function test_pane_admin_cannot_tighten_bounds_past_existing_organization_overrides(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);
        $organizationAdministrator = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $organization = $this->createOrganization('Existing Override Bounds Workspace');

        $this->tenancy->addOrReactivateMembership(
            $organization,
            $organizationAdministrator,
            OrganizationMembership::ROLE_ADMINISTRATOR
        );

        $this->settings->setOrganizationOverride(
            $organizationAdministrator,
            $organization,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            172_800
        );

        try {
            $this->settings->setInstallationOverride(
                $paneAdministrator,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS,
                86_400
            );
            $this->fail('Expected maximum bound tightening past an existing organization override to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization invitation maximum expiry cannot be lower than existing invitation overrides.',
                $exception->getMessage()
            );
        }

        try {
            $this->settings->setInstallationOverride(
                $paneAdministrator,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS,
                259_200
            );
            $this->fail('Expected minimum bound tightening past an existing organization override to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization invitation minimum expiry cannot exceed existing invitation overrides.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            172_800,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS, $organization)
        );
    }

    public function test_pane_admin_cannot_tighten_bounds_past_installation_invitation_override(): void
    {
        $paneAdministrator = $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID);

        $this->settings->setInstallationOverride(
            $paneAdministrator,
            SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
            172_800
        );

        try {
            $this->settings->setInstallationOverride(
                $paneAdministrator,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS,
                86_400
            );
            $this->fail('Expected maximum bound tightening past an existing installation override to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization invitation maximum expiry cannot be lower than existing invitation overrides.',
                $exception->getMessage()
            );
        }

        try {
            $this->settings->setInstallationOverride(
                $paneAdministrator,
                SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS,
                259_200
            );
            $this->fail('Expected minimum bound tightening past an existing installation override to fail.');
        } catch (InvalidArgumentException $exception) {
            $this->assertSame(
                'Organization invitation minimum expiry cannot exceed existing invitation overrides.',
                $exception->getMessage()
            );
        }

        $this->assertSame(
            172_800,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
        );
    }

    public function test_defaults_are_resolved_from_migrated_versioned_state(): void
    {
        $default = SettingDefault::query()
            ->where('setting_key', SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
            ->firstOrFail();

        $this->assertSame(1, $default->default_version);
        $this->assertSame(
            604_800,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
        );

        $default->update([
            'value' => 1_209_600,
            'default_version' => 2,
        ]);

        $this->assertSame(
            1_209_600,
            $this->settings->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
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

    private function createOrganization(string $name): Organization
    {
        return $this->tenancy->createOrganization($name, Str::slug($name).'-'.Str::uuid());
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
