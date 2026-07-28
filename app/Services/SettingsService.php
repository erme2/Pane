<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationMembership;
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

    public function resolve(string $key, ?Organization $organization = null): mixed
    {
        $definition = $this->registry->get($key);

        if (
            $organization instanceof Organization
            && $definition->allowsScope(SettingDefinition::SCOPE_ORGANIZATION)
        ) {
            $organizationOverride = $this->findOverride(
                $key,
                SettingDefinition::SCOPE_ORGANIZATION,
                (string) $organization->getKey()
            );

            if ($organizationOverride instanceof SettingOverride) {
                return $organizationOverride->value;
            }
        }

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
            organization: null,
        );
    }

    public function setOrganizationOverride(User $actor, Organization $organization, string $key, mixed $value): SettingOverride
    {
        $definition = $this->registry->get($key);

        $this->assertScope($definition, SettingDefinition::SCOPE_ORGANIZATION);
        $this->assertOrganizationAdministrator($actor, $organization);
        $this->assertAdministrator($definition, SettingDefinition::ADMINISTRATOR_ORGANIZATION);
        $this->assertValidValue($definition, $value);

        return $this->saveOverride(
            actor: $actor,
            definition: $definition,
            scope: SettingDefinition::SCOPE_ORGANIZATION,
            scopeId: (string) $organization->getKey(),
            value: $value,
            organization: $organization,
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
        ?Organization $organization,
    ): SettingOverride {
        return DB::transaction(function () use ($actor, $definition, $scope, $scopeId, $value, $organization): SettingOverride {
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
                'organization' => $organization,
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

    private function assertOrganizationAdministrator(User $actor, Organization $organization): void
    {
        $membership = $organization->activeMembershipFor($actor);

        if (
            ! $organization->isActive()
            || ! $membership instanceof OrganizationMembership
            || ! $membership->isAdministrator()
        ) {
            throw new DomainException('Only active organization administrators can change organization settings.');
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

        [$minimum, $maximum] = $this->boundsFor($definition);

        if (is_int($value) && $minimum !== null && $value < $minimum) {
            throw new InvalidArgumentException("Setting [{$definition->key}] must be at least $minimum.");
        }

        if (is_int($value) && $maximum !== null && $value > $maximum) {
            throw new InvalidArgumentException("Setting [{$definition->key}] must be at most $maximum.");
        }

        if ($definition->key === SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS) {
            $currentMaximum = $this->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS);

            if (is_int($currentMaximum) && is_int($value) && $value > $currentMaximum) {
                throw new InvalidArgumentException('Organization invitation minimum expiry cannot exceed the maximum expiry.');
            }

            $this->assertExistingOrganizationOverridesRespectMinimum($value);
        }

        if ($definition->key === SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MAX_SECONDS) {
            $currentMinimum = $this->resolve(SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_MIN_SECONDS);

            if (is_int($currentMinimum) && is_int($value) && $value < $currentMinimum) {
                throw new InvalidArgumentException('Organization invitation maximum expiry cannot be lower than the minimum expiry.');
            }

            $this->assertExistingOrganizationOverridesRespectMaximum($value);
        }
    }

    private function assertExistingOrganizationOverridesRespectMinimum(int $minimum): void
    {
        $hasInvalidOverride = SettingOverride::query()
            ->where('setting_key', SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
            ->where('scope', SettingDefinition::SCOPE_ORGANIZATION)
            ->get()
            ->contains(static fn (SettingOverride $override): bool => is_int($override->value) && $override->value < $minimum);

        if ($hasInvalidOverride) {
            throw new InvalidArgumentException('Organization invitation minimum expiry cannot exceed existing organization overrides.');
        }
    }

    private function assertExistingOrganizationOverridesRespectMaximum(int $maximum): void
    {
        $hasInvalidOverride = SettingOverride::query()
            ->where('setting_key', SettingsRegistry::ORGANIZATION_INVITATION_EXPIRY_SECONDS)
            ->where('scope', SettingDefinition::SCOPE_ORGANIZATION)
            ->get()
            ->contains(static fn (SettingOverride $override): bool => is_int($override->value) && $override->value > $maximum);

        if ($hasInvalidOverride) {
            throw new InvalidArgumentException('Organization invitation maximum expiry cannot be lower than existing organization overrides.');
        }
    }

    /**
     * @return array{0: int|float|null, 1: int|float|null}
     */
    private function boundsFor(SettingDefinition $definition): array
    {
        $minimum = $definition->minimum;
        $maximum = $definition->maximum;

        if ($definition->minimumSettingKey !== null) {
            $resolvedMinimum = $this->resolve($definition->minimumSettingKey);
            $minimum = is_int($resolvedMinimum) || is_float($resolvedMinimum) ? $resolvedMinimum : $minimum;
        }

        if ($definition->maximumSettingKey !== null) {
            $resolvedMaximum = $this->resolve($definition->maximumSettingKey);
            $maximum = is_int($resolvedMaximum) || is_float($resolvedMaximum) ? $resolvedMaximum : $maximum;
        }

        return [$minimum, $maximum];
    }
}
