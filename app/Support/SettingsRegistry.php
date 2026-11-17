<?php

namespace App\Support;

use InvalidArgumentException;

class SettingsRegistry
{
    public const string PANE_ADMIN_INVITATION_EXPIRY_SECONDS = 'pane_admin.invitation_expiry_seconds';

    /**
     * @return array<string, SettingDefinition>
     */
    public function all(): array
    {
        return [
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
