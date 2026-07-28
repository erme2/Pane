<?php

use App\Support\PaneTable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep migration defaults literal so future registry changes do not rewrite
     * historical installation defaults during fresh installs.
     *
     * @var array<int, array{setting_key: string, value: int, default_version: int}>
     */
    private const array VERSION_1_DEFAULTS = [
        [
            'setting_key' => 'organization.invitation_expiry_min_seconds',
            'value' => 3600,
            'default_version' => 1,
        ],
        [
            'setting_key' => 'organization.invitation_expiry_max_seconds',
            'value' => 2_592_000,
            'default_version' => 1,
        ],
        [
            'setting_key' => 'organization.invitation_expiry_seconds',
            'value' => 604_800,
            'default_version' => 1,
        ],
        [
            'setting_key' => 'pane_admin.invitation_expiry_seconds',
            'value' => 86_400,
            'default_version' => 1,
        ],
    ];

    public function up(): void
    {
        Schema::create(PaneTable::name(PaneTable::SETTING_DEFAULTS), function (Blueprint $table) {
            $table->string('setting_key')->primary();
            $table->json('value');
            $table->unsignedInteger('default_version');
            $table->timestamps();
        });

        $this->seedVersionOneDefaults();

        Schema::create(PaneTable::name(PaneTable::SETTING_OVERRIDES), function (Blueprint $table) {
            $table->uuid('setting_override_id')->primary();
            $table->string('setting_key')->index();
            $table->string('scope')->index();
            $table->string('scope_id')->default('')->index();
            $table->json('value');
            $table->unsignedInteger('default_version');
            $table->unsignedInteger('updated_by_user_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['setting_key', 'scope', 'scope_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(PaneTable::name(PaneTable::SETTING_OVERRIDES));
        Schema::dropIfExists(PaneTable::name(PaneTable::SETTING_DEFAULTS));
    }

    private function seedVersionOneDefaults(): void
    {
        $now = now();
        $rows = array_map(
            static fn (array $default): array => [
                'setting_key' => $default['setting_key'],
                'value' => json_encode($default['value'], JSON_THROW_ON_ERROR),
                'default_version' => $default['default_version'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::VERSION_1_DEFAULTS
        );

        DB::table(PaneTable::name(PaneTable::SETTING_DEFAULTS))->insert($rows);
    }
};
