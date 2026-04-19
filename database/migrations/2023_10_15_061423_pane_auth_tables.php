<?php

use App\Mappers\AbstractMapper;
use App\Helpers\SystemMigrationsHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use SystemMigrationsHelper;

    private array $insertKeys = [];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(
            $this->getUsersTableName(),
            function (Blueprint $table) {
            $table->unsignedInteger('user_id')->autoIncrement();
            $table->unsignedInteger('user_type_id')->index();
            $table->string('name')->unique()->index();
            $table->string('email')->unique()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->json('details')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(false);
            $table->dateTimeTz('last_login_at')->nullable();
            $table->timestamps();
        });
        Schema::create(
            $this->getUsersTypesTableName(),
            function (Blueprint $table) {
            $table->unsignedInteger('user_type_id')->autoIncrement();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('details')->nullable();
            $table->timestamps();
        });

        DB::table($this->getUsersTypesTableName())->insert([
            [
                'user_type_id' => 1,
                'name' => 'Administrator',
                'slug' => 'administrator',
                'details' => json_encode([
                    'description' => 'Administrator',
                ]),
            ],
            [
                'user_type_id' => 2,
                'name' => 'User',
                'slug' => 'user',
                'details' => json_encode([
                    'description' => 'User',
                ]),
            ],
        ]);

        $this->insertAllTableRecords();
        $this->insertUserTableFields();
        $this->insertUserTypeTableFields();
        $this->insertUserFieldsValidations();
        $this->insertUserTypeFieldsValidations();

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists($this->getUsersTableName());
        Schema::dropIfExists($this->getUsersTypesTableName());
    }

    private function insertAllTableRecords(): void
    {
        $this->insertKeys['users']['table_id'] =
            DB::table($this->getTablesTableName())->insertGetId([
                'name' => AbstractMapper::TABLES['users'],
                'sql_name' => AbstractMapper::TABLES['users'],
                'description' => 'Users table',
            ]);
        $this->insertKeys['user_types']['table_id'] =
            DB::table($this->getTablesTableName())->insertGetId([
                'name' => AbstractMapper::TABLES['user_types'],
                'sql_name' => AbstractMapper::TABLES['user_types'],
                'description' => 'User types table',
            ]);
    }

    private function insertUserTableFields(): void
    {
        $this->insertKeys['users']['fields']['user_id'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
                'name' => 'user_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['user_type_id'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
                'name' => 'user_type_id',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['name'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['email'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'email',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['email_verified_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['date'],
                'name' => 'email_verified_at',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['password'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['password'],
                'name' => 'password',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => '',
            ]);
        $this->insertKeys['users']['fields']['details'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['json'],
                'name' => 'details',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['settings'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['json'],
                'name' => 'settings',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['is_active'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['boolean'],
                'name' => 'is_active',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => 'false',
            ]);
        $this->insertKeys['users']['fields']['last_login_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['date'],
                'name' => 'last_login_at',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['created_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['date'],
                'name' => 'created_at',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['users']['fields']['updated_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['users']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['date'],
                'name' => 'updated_at',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
    }

    private function insertUserTypeTableFields(): void
    {
        $this->insertKeys['user_types']['fields']['user_type_id'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
                'name' => 'user_type_id',
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['user_types']['fields']['name'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'name',
                'primary' => false,
                'index' => false,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['user_types']['fields']['slug'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['string'],
                'name' => 'slug',
                'primary' => false,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['user_types']['fields']['details'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['text'],
                'name' => 'details',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
        $this->insertKeys['user_types']['fields']['created_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
                'name' => 'created_at',
                'primary' => true,
                'index' => true,
                'nullable' => false,
                'default' => null,
            ]);
        $this->insertKeys['user_types']['fields']['updated_at'] =
            DB::table($this->getFieldsTableName())->insertGetId([
                'table_id' => $this->insertKeys['user_types']['table_id'],
                'field_type_id' => AbstractMapper::FIELD_TYPES['number'],
                'name' => 'updated_at',
                'primary' => false,
                'index' => false,
                'nullable' => true,
                'default' => null,
            ]);
    }

    private function insertUserFieldsValidations(): void
    {
        DB::table($this->getFieldsValidationsTableName())->insert([
            [
                'field_id' => $this->insertKeys['users']['fields']['user_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => $this->getUsersTableName().',user_id',
                'message' => 'User ID does not exists',
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['user_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => $this->getUsersTypesTableName().',user_type_id',
                'message' => 'User type ID does not exists',
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => $this->getUsersTableName().',name',
                'message' => 'Name already exists',
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => 255,
                'message' => 'Name is too long',
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['email'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => $this->getUsersTableName().',email',
                'message' => null,
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['email'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => 255,
                'message' => null,
            ],
            [
                'field_id' => $this->insertKeys['users']['fields']['password'],
                'validation_type' => AbstractMapper::VALIDATION_TYPES['min'],
                'value' => '6',
                'message' => null,
            ],
        ]);
    }

    private function insertUserTypeFieldsValidations(): void
    {
        DB::table($this->getFieldsValidationsTableName())->insert([
            [
                'field_id' => $this->insertKeys['user_types']['fields']['user_type_id'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['exists'],
                'value' => $this->getUsersTypesTableName().',user_type_id',
            ],
            [
                'field_id' => $this->insertKeys['user_types']['fields']['name'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
            [
                'field_id' => $this->insertKeys['user_types']['fields']['slug'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['unique'],
                'value' => $this->getUsersTypesTableName().',slug'
            ],
            [
                'field_id' => $this->insertKeys['user_types']['fields']['slug'],
                'validation_type_id' => AbstractMapper::VALIDATION_TYPES['max'],
                'value' => '255',
            ],
        ]);
    }
};
