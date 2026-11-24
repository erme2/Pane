<?php

namespace App\Support;

use App\Mappers\AbstractMapper;

class PaneTable
{
    public const string AUDIT_EVENTS = 'audit_events';

    public const string PANE_ADMIN_INVITATIONS = 'admin_invitations';

    public const string USER_INVITATIONS = 'user_invitations';

    public const string MESSAGES = 'messages';

    public const string PANE_INSTALLATION_LOCKS = 'installation_locks';

    public const string SETTING_DEFAULTS = 'setting_defaults';

    public const string SETTING_OVERRIDES = 'setting_overrides';

    public const string MAPS_SOURCES = 'maps_sources';

    public const string MAPS_TABLES = 'maps_tables';

    public const string MAPS_FIELDS = 'maps_fields';

    public const string MAPS_VERSIONS = 'maps_versions';

    public const string MAPS_MENU = 'maps_menu';

    public const string MAPS_FUNCTIONS = 'maps_functions';

    public const string MAPS_SETTINGS = 'maps_settings';

    public const string MAPS_SETTING_VALUES = 'maps_setting_values';

    /** The maps' own tables: always system maps, whatever the prefix (decision 32). */
    public const array MAPS = [
        self::MAPS_SOURCES, self::MAPS_TABLES, self::MAPS_FIELDS, self::MAPS_VERSIONS,
        self::MAPS_MENU, self::MAPS_FUNCTIONS, self::MAPS_SETTINGS, self::MAPS_SETTING_VALUES,
    ];

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
