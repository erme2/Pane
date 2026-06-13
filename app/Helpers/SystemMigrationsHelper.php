<?php

namespace App\Helpers;

use App\Mappers\AbstractMapper;

trait SystemMigrationsHelper
{
    /**
     * Returns the name of the tables table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getTablesTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['tables'];
    }

    /**
     * Returns the name of fields table, with the previdx if it exists.
     *
     * @return string
     */
    protected function getFieldsTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['fields'];
    }

    /**
     * Returns the name of fields validations table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getFieldsValidationsTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['field_validations'];
    }

    /**
     * Returns the name of validations types table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getValidationsTypesTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['validation_types'];
    }

    /**
     * Returns the name of fields types table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getFieldsTypesTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['field_types'];
    }

    /**
     * Returns the name of users table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getUsersTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX . AbstractMapper::TABLES['users'];

    }

    /**
     * Returns the name of users types table, with the prefix if it exists.
     *
     * @return string
     */
    protected function getUsersTypesTableName(): string
    {
        return (env('DB_TABLE_PREFIX')) . AbstractMapper::MAP_TABLES_PREFIX.AbstractMapper::TABLES['user_types'];
    }
}
