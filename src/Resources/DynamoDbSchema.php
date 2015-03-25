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

use Aws\DynamoDb\Enum\KeyType;
use Aws\DynamoDb\Enum\Type;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Rave\Aws\Services\DynamoDb;
use DreamFactory\Rave\Utility\DbUtilities;

class DynamoDbSchema extends BaseNoSqlDbSchemaResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const TABLE_INDICATOR = 'TableName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|DynamoDb
     */
    protected $service = null;
    /**
     * @var array
     */
    protected $_defaultCreateTable = array(
        'AttributeDefinitions'  => array(
            array(
                'AttributeName' => 'id',
                'AttributeType' => Type::S
            )
        ),
        'KeySchema'             => array(
            array(
                'AttributeName' => 'id',
                'KeyType'       => KeyType::HASH
            )
        ),
        'ProvisionedThroughput' => array(
            'ReadCapacityUnits'  => 10,
            'WriteCapacityUnits' => 20
        )
    );

    /**
     * {@inheritdoc}
     */
    public function listResources($include_properties = null)
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getTables();

        if (empty($include_properties))
        {
            return array('resource' => $_names);
        }

        $_extras = DbUtilities::getSchemaExtrasForTables( $this->service->getServiceId(), $_names, false, 'table,label,plural' );

        $_tables = array();
        foreach ( $_names as $name )
        {
            $label = '';
            $plural = '';
            foreach ( $_extras as $each )
            {
                if ( 0 == strcasecmp( $name, ArrayUtils::get( $each, 'table', '' ) ) )
                {
                    $label = ArrayUtils::get( $each, 'label' );
                    $plural = ArrayUtils::get( $each, 'plural' );
                    break;
                }
            }

            if ( empty( $label ) )
            {
                $label = Inflector::camelize( $name, ['_','.'], true );
            }

            if ( empty( $plural ) )
            {
                $plural = Inflector::pluralize( $label );
            }

            $_tables[] = array('name' => $name, 'label' => $label, 'plural' => $plural);
        }

        return $this->makeResourceList($_tables, $include_properties, true);
    }

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
            $_result = $this->service->getConnection()->describeTable( array(static::TABLE_INDICATOR => $_name) );

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
            $_result = $this->service->getConnection()->createTable( $_properties );

            // Wait until the table is created and active
            $this->service->getConnection()->waitUntilTableExists( array(static::TABLE_INDICATOR => $table) );

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
            $_result = $this->service->getConnection()->updateTable( $_properties );

            // Wait until the table is active again after updating
            $this->service->getConnection()->waitUntilTableExists( array(static::TABLE_INDICATOR => $table) );

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
            $_result = $this->service->getConnection()->deleteTable( array(static::TABLE_INDICATOR => $_name) );

            // Wait until the table is truly gone
            $this->service->getConnection()->waitUntilTableNotExists( array(static::TABLE_INDICATOR => $_name) );

            return array_merge( array('name' => $_name), $_result['TableDescription'] );
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to delete table '$_name'.\n{$_ex->getMessage()}" );
        }
    }
}