<?php

namespace App\Support;

class SettingDefinition
{
    public const string TYPE_INTEGER = 'integer';

    public const string SCOPE_INSTALLATION = 'installation';

    public const string SCOPE_ORGANIZATION = 'organization';

    public const string ADMINISTRATOR_PANE = 'pane_administrator';

    public const string ADMINISTRATOR_ORGANIZATION = 'organization_administrator';

    /**
     * @param array<int, string> $scopes
     * @param array<int, string> $administrators
     */
    public function __construct(
        public readonly string $key,
        public readonly string $type,
        public readonly mixed $defaultValue,
        public readonly int $defaultVersion,
        public readonly array $scopes,
        public readonly array $administrators,
        public readonly int|float|null $minimum = null,
        public readonly int|float|null $maximum = null,
        public readonly ?string $minimumSettingKey = null,
        public readonly ?string $maximumSettingKey = null,
    ) {}

    public function allowsScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    public function allowsAdministrator(string $administrator): bool
    {
        return in_array($administrator, $this->administrators, true);
    }
}
