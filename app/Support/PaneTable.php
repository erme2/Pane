<?php

namespace App\Support;

use App\Mappers\AbstractMapper;

class PaneTable
{
    public const string ORGANIZATIONS = 'organizations';

    public const string ORGANIZATION_MEMBERSHIPS = 'organization_memberships';

    public const string AUDIT_EVENTS = 'audit_events';

    public const string PANE_ADMIN_INVITATIONS = 'pane_admin_invitations';

    public const string PANE_INSTALLATION_LOCKS = 'pane_installation_locks';

    public const string SETTING_DEFAULTS = 'setting_defaults';

    public const string SETTING_OVERRIDES = 'setting_overrides';

    public static function name(string $table): string
    {
        return (string) config('database.table_prefix', 'pane_').AbstractMapper::MAP_TABLES_PREFIX.$table;
    }
}
