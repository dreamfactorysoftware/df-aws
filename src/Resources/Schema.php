<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Rave\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Rave\Resources\BaseNoSqlDbSchemaResource;

class Schema extends BaseNoSqlDbSchemaResource
{
    protected function _getTablesAsArray()
    {
        $_out = array();
        do
        {
            $_result = $this->_dbConn->listTables(
                array(
                    'Limit'                   => 100, // arbitrary limit
                    'ExclusiveStartTableName' => isset( $_result ) ? $_result['LastEvaluatedTableName'] : null
                )
            );

            $_out = array_merge( $_out, $_result['TableNames'] );
        }
        while ( $_result['LastEvaluatedTableName'] );

        return $_out;
    }

    // REST service implementation

    /**
     * {@inheritdoc}
     */
    protected function _listTables( /** @noinspection PhpUnusedParameterInspection */ $refresh = true )
    {
        $_resources = array();
        $_result = $this->_getTablesAsArray();
        foreach ( $_result as $_table )
        {
            $_resources[] = array('name' => $_table, static::TABLE_INDICATOR => $_table);
        }

        return $_resources;
    }

    // Handle administrative options, table add, delete, etc

    /**
     * {@inheritdoc}
     */
    public function describeTable( $table, $refresh = true  )
    {
        $_name =
            ( is_array( $table ) ) ? ArrayUtils::get( $table, 'name', ArrayUtils::get( $table, static::TABLE_INDICATOR ) )
                : $table;
        try
        {
            $_result = $this->_dbConn->describeTable( array(static::TABLE_INDICATOR => $_name) );

            // The result of an operation can be used like an array
            $_out = $_result['Table'];
            $_out['name'] = $_name;
            $_out['access'] = $this->getPermissions( $_name );

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to get table properties for table '$_name'.\n{$_ex->getMessage(
            )}" );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable( $table, $properties = array(), $check_exist = false, $return_schema = false )
    {
        if ( empty( $table ) )
        {
            $table = ArrayUtils::get( $properties, static::TABLE_INDICATOR );
        }
        if ( empty( $table ) )
        {
            throw new BadRequestException( "No 'name' field in data." );
        }

        try
        {
            $_properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $this->_defaultCreateTable,
                $properties
            );
            $_result = $this->_dbConn->createTable( $_properties );

            // Wait until the table is created and active
            $this->_dbConn->waitUntilTableExists( array(static::TABLE_INDICATOR => $table) );

            $_out = array_merge( array('name' => $table), $_result['TableDescription'] );

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to create table '$table'.\n{$_ex->getMessage()}" );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable( $table, $properties = array(), $allow_delete_fields = false, $return_schema = false )
    {
        if ( empty( $table ) )
        {
            $table = ArrayUtils::get( $properties, static::TABLE_INDICATOR );
        }
        if ( empty( $table ) )
        {
            throw new BadRequestException( "No 'name' field in data." );
        }

        try
        {
            // Update the provisioned throughput capacity of the table
            $_properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $properties
            );
            $_result = $this->_dbConn->updateTable( $_properties );

            // Wait until the table is active again after updating
            $this->_dbConn->waitUntilTableExists( array(static::TABLE_INDICATOR => $table) );

            return array_merge( array('name' => $table), $_result['TableDescription'] );
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to update table '$table'.\n{$_ex->getMessage()}" );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable( $table, $check_empty = false )
    {
        $_name =
            ( is_array( $table ) ) ? ArrayUtils::get( $table, 'name', ArrayUtils::get( $table, static::TABLE_INDICATOR ) )
                : $table;
        if ( empty( $_name ) )
        {
            throw new BadRequestException( 'Table name can not be empty.' );
        }

        try
        {
            $_result = $this->_dbConn->deleteTable( array(static::TABLE_INDICATOR => $_name) );

            // Wait until the table is truly gone
            $this->_dbConn->waitUntilTableNotExists( array(static::TABLE_INDICATOR => $_name) );

            return array_merge( array('name' => $_name), $_result['TableDescription'] );
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to delete table '$_name'.\n{$_ex->getMessage()}" );
        }
    }
}