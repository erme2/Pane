<?php

namespace App\Support;

use App\Mappers\AbstractMapper;

class PaneTable
{
    public const string ORGANIZATIONS = 'organizations';

    public const string ORGANIZATION_MEMBERSHIPS = 'organization_memberships';

    public const string ORGANIZATION_INVITATIONS = 'organization_invitations';

    public const string APPLICATIONS = 'applications';

    public const string MANAGED_CREDENTIAL_SECRETS = 'managed_credential_secrets';

    public const string MANAGED_CONNECTIONS = 'managed_connections';

    public const string AUDIT_EVENTS = 'audit_events';

    public const string MESSAGES = 'messages';

    public const string PANE_ADMIN_INVITATIONS = 'admin_invitations';

    public const string PANE_INSTALLATION_LOCKS = 'installation_locks';

    public const string SETTING_DEFAULTS = 'setting_defaults';

    public const string SETTING_OVERRIDES = 'setting_overrides';

    public const string MAPS_SOURCES = 'maps_sources';

    public const string MAPS_TABLES = 'maps_tables';

    public const string MAPS_FIELDS = 'maps_fields';

    public const string MAPS_VERSIONS = 'maps_versions';

    /** The maps' own tables: always system maps, whatever the prefix (decision 32). */
    public const array MAPS = [self::MAPS_SOURCES, self::MAPS_TABLES, self::MAPS_FIELDS, self::MAPS_VERSIONS];

    public static function name(string $table): string
    {
        return self::prefix().$table;
    }

    public static function mapName(string $table): string
    {
        return self::prefix().AbstractMapper::MAP_TABLES_PREFIX.$table;
    }

    public static function prefix(): string
    {
        return (string) config('database.table_prefix', 'pane_');
    }
}
