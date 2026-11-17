<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\SettingDefault;
use App\Models\SettingOverride;
use App\Models\User;
use App\Support\SettingDefinition;
use App\Support\SettingsRegistry;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SettingsService
{
    public function __construct(
        private readonly SettingsRegistry $registry,
        private readonly AuditEventService $audit,
    ) {}

    public function resolve(string $key): mixed
    {
        $definition = $this->registry->get($key);

        if ($definition->allowsScope(SettingDefinition::SCOPE_INSTALLATION)) {
            $installationOverride = $this->findOverride(
                $key,
                SettingDefinition::SCOPE_INSTALLATION,
                ''
            );

            if ($installationOverride instanceof SettingOverride) {
                return $installationOverride->value;
            }
        }

        return $this->defaultValueFor($definition);
    }

    public function setInstallationOverride(User $actor, string $key, mixed $value): SettingOverride
    {
        $definition = $this->registry->get($key);

        $this->assertScope($definition, SettingDefinition::SCOPE_INSTALLATION);
        $this->assertPaneAdministrator($actor);
        $this->assertAdministrator($definition, SettingDefinition::ADMINISTRATOR_PANE);
        $this->assertValidValue($definition, $value);

        return $this->saveOverride(
            actor: $actor,
            definition: $definition,
            scope: SettingDefinition::SCOPE_INSTALLATION,
            scopeId: '',
            value: $value,
        );
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function registeredSettings(): array
    {
        return $this->registry->all();
    }

    private function saveOverride(
        User $actor,
        SettingDefinition $definition,
        string $scope,
        string $scopeId,
        mixed $value,
    ): SettingOverride {
        return DB::transaction(function () use ($actor, $definition, $scope, $scopeId, $value): SettingOverride {
            $override = SettingOverride::query()->updateOrCreate(
                [
                    'setting_key' => $definition->key,
                    'scope' => $scope,
                    'scope_id' => $scopeId,
                ],
                [
                    'value' => $value,
                    'default_version' => $definition->defaultVersion,
                    'updated_by_user_id' => $actor->getKey(),
                ],
            );

            $this->audit->record('settings.override.update', AuditEvent::OUTCOME_SUCCESS, [
                'real_actor' => $actor,
                'effective_user' => $actor,
                'resource_ids' => [
                    'setting_key' => $definition->key,
                    'setting_override_id' => $override->getKey(),
                ],
                'changed_columns' => ['value'],
                'metadata' => [
                    'scope' => $scope,
                    'default_version' => $definition->defaultVersion,
                ],
            ]);

            return $override;
        });
    }

    private function findOverride(string $key, string $scope, string $scopeId): ?SettingOverride
    {
        $override = SettingOverride::query()
            ->where('setting_key', $key)
            ->where('scope', $scope)
            ->where('scope_id', $scopeId)
            ->first();

        return $override instanceof SettingOverride ? $override : null;
    }

    private function defaultValueFor(SettingDefinition $definition): mixed
    {
        $default = SettingDefault::query()
            ->where('setting_key', $definition->key)
            ->first();

        return $default instanceof SettingDefault ? $default->value : $definition->defaultValue;
    }

    private function assertScope(SettingDefinition $definition, string $scope): void
    {
        if (! $definition->allowsScope($scope)) {
            throw new InvalidArgumentException("Setting [{$definition->key}] does not allow [$scope] overrides.");
        }
    }

    private function assertPaneAdministrator(User $actor): void
    {
        if (! $actor->isPaneAdministrator()) {
            throw new DomainException('Only Pane administrators can change installation settings.');
        }
    }

    private function assertAdministrator(SettingDefinition $definition, string $administrator): void
    {
        if (! $definition->allowsAdministrator($administrator)) {
            throw new DomainException("Setting [{$definition->key}] cannot be changed by [$administrator].");
        }
    }

    private function assertValidValue(SettingDefinition $definition, mixed $value): void
    {
        if ($definition->type === SettingDefinition::TYPE_INTEGER && ! is_int($value)) {
            throw new InvalidArgumentException("Setting [{$definition->key}] requires an integer value.");
        }

        $minimum = $definition->minimum;
        $maximum = $definition->maximum;

        if (is_int($value) && $minimum !== null && $value < $minimum) {
            throw new InvalidArgumentException("Setting [{$definition->key}] must be at least $minimum.");
        }

        if (is_int($value) && $maximum !== null && $value > $maximum) {
            throw new InvalidArgumentException("Setting [{$definition->key}] must be at most $maximum.");
        }
    }
}
