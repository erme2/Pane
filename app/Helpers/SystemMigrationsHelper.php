<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;
use App\Support\PaneTable;

trait SystemMigrationsHelper
{
    /**
     * Returns the name of the tables table, with the prefix if it exists.
     */
    protected function getTablesTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['tables']);
    }

    /**
     * Returns the name of fields table, with the previdx if it exists.
     */
    protected function getFieldsTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['fields']);
    }

    /**
     * Returns the name of fields validations table, with the prefix if it exists.
     */
    protected function getFieldsValidationsTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['field_validations']);
    }

    /**
     * Returns the name of validations types table, with the prefix if it exists.
     */
    protected function getValidationsTypesTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['validation_types']);
    }

    /**
     * Returns the name of fields types table, with the prefix if it exists.
     */
    protected function getFieldsTypesTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['field_types']);
    }

    /**
     * Returns the name of users table, with the prefix if it exists.
     */
    protected function getUsersTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['users']);

    }

    /**
     * Returns the name of users types table, with the prefix if it exists.
     */
    protected function getUsersTypesTableName(): string
    {
        return PaneTable::mapName(AbstractMapper::TABLES['user_types']);
    }
}
