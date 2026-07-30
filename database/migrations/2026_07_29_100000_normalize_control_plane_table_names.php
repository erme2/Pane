<?php

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @var array<int, array{legacy: string, current: string}>
     */
    private array $renames;

    public function __construct()
    {
        $prefix = PaneTable::prefix();
        $mapPrefix = AbstractMapper::MAP_TABLES_PREFIX;

        $this->renames = [
            ['legacy' => $prefix.$mapPrefix.PaneTable::ORGANIZATIONS, 'current' => PaneTable::name(PaneTable::ORGANIZATIONS)],
            ['legacy' => $prefix.$mapPrefix.PaneTable::ORGANIZATION_MEMBERSHIPS, 'current' => PaneTable::name(PaneTable::ORGANIZATION_MEMBERSHIPS)],
            ['legacy' => $prefix.$mapPrefix.PaneTable::AUDIT_EVENTS, 'current' => PaneTable::name(PaneTable::AUDIT_EVENTS)],
            ['legacy' => $prefix.$mapPrefix.PaneTable::SETTING_DEFAULTS, 'current' => PaneTable::name(PaneTable::SETTING_DEFAULTS)],
            ['legacy' => $prefix.$mapPrefix.PaneTable::SETTING_OVERRIDES, 'current' => PaneTable::name(PaneTable::SETTING_OVERRIDES)],
            ['legacy' => $prefix.$mapPrefix.'pane_installation_locks', 'current' => PaneTable::name(PaneTable::PANE_INSTALLATION_LOCKS)],
            ['legacy' => $prefix.$mapPrefix.'pane_admin_invitations', 'current' => PaneTable::name(PaneTable::PANE_ADMIN_INVITATIONS)],
        ];
    }

    public function up(): void
    {
        foreach ($this->renames as $rename) {
            $legacyExists = Schema::hasTable($rename['legacy']);
            $currentExists = Schema::hasTable($rename['current']);

            if ($legacyExists && $currentExists) {
                throw new RuntimeException("Cannot rename [{$rename['legacy']}] to [{$rename['current']}] because both tables exist.");
            }

            if ($legacyExists) {
                Schema::rename($rename['legacy'], $rename['current']);
            }
        }
    }

    public function down(): void
    {
        // Intentionally no-op. Earlier migrations now own the current table names
        // for rollback, and fresh installs never create the legacy names.
    }
};
