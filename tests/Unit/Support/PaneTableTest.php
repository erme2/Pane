<?php

namespace Tests\Unit\Support;

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;
use Tests\TestCase;

class PaneTableTest extends TestCase
{
    public function test_control_plane_table_names_use_the_configured_prefix_without_map_prefix(): void
    {
        config()->set('database.table_prefix', 'tenant_');

        $this->assertSame('tenant_applications', PaneTable::name(PaneTable::APPLICATIONS));
        $this->assertSame('tenant_admin_invitations', PaneTable::name(PaneTable::PANE_ADMIN_INVITATIONS));
        $this->assertSame('tenant_installation_locks', PaneTable::name(PaneTable::PANE_INSTALLATION_LOCKS));
    }

    public function test_legacy_map_table_names_keep_the_mapper_prefix(): void
    {
        config()->set('database.table_prefix', 'tenant_');

        $this->assertSame(
            'tenant_map_tables',
            PaneTable::mapName(AbstractMapper::TABLES['tables'])
        );
    }
}
