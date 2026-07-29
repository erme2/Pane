<?php

namespace App\Support;

use InvalidArgumentException;

class SettingsRegistry
{
    public const string ORGANIZATION_INVITATION_EXPIRY_SECONDS = 'organization.invitation_expiry_seconds';

    public const string ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS = 'organization.invitation_expiry_min_seconds';

    public const string ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS = 'organization.invitation_expiry_max_seconds';

    public const string PANE_ADMIN_INVITATION_EXPIRY_SECONDS = 'pane_admin.invitation_expiry_seconds';

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return [
            self::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS => new SettingDefinition(
                key: self::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS,
                type: SettingDefinition::TYPE_INTEGER,
                defaultValue: 3600,
                defaultVersion: 1,
                scopes: [SettingDefinition::SCOPE_INSTALLATION],
                administrators: [SettingDefinition::ADMINISTRATOR_PANE],
                minimum: 300,
                maximum: 2_592_000,
            ),
            self::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS => new SettingDefinition(
                key: self::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS,
                type: SettingDefinition::TYPE_INTEGER,
                defaultValue: 2_592_000,
                defaultVersion: 1,
                scopes: [SettingDefinition::SCOPE_INSTALLATION],
                administrators: [SettingDefinition::ADMINISTRATOR_PANE],
                minimum: 3600,
                maximum: 31_536_000,
            ),
            self::ORGANIZATION_INVITATION_EXPIRY_SECONDS => new SettingDefinition(
                key: self::ORGANIZATION_INVITATION_EXPIRY_SECONDS,
                type: SettingDefinition::TYPE_INTEGER,
                defaultValue: 604_800,
                defaultVersion: 1,
                scopes: [
                    SettingDefinition::SCOPE_INSTALLATION,
                    SettingDefinition::SCOPE_ORGANIZATION,
                ],
                administrators: [
                    SettingDefinition::ADMINISTRATOR_PANE,
                    SettingDefinition::ADMINISTRATOR_ORGANIZATION,
                ],
                minimumSettingKey: self::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS,
                maximumSettingKey: self::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS,
            ),
            self::PANE_ADMIN_INVITATION_EXPIRY_SECONDS => new SettingDefinition(
                key: self::PANE_ADMIN_INVITATION_EXPIRY_SECONDS,
                type: SettingDefinition::TYPE_INTEGER,
                defaultValue: 86_400,
                defaultVersion: 1,
                scopes: [SettingDefinition::SCOPE_INSTALLATION],
                administrators: [SettingDefinition::ADMINISTRATOR_PANE],
                minimum: 3600,
                maximum: 604_800,
            ),
        ];
    }

    public function get(string $key): SettingDefinition
    {
        $definition = $this->all()[$key] ?? null;

        if (! $definition instanceof SettingDefinition) {
            throw new InvalidArgumentException("Unregistered setting [$key].");
        }

        return $definition;
    }
}
