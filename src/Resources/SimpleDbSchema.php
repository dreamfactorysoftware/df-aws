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
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Rave\Aws\Services\SimpleDb;
use DreamFactory\Rave\Utility\DbUtilities;

class SimpleDbSchema extends BaseNoSqlDbSchemaResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const TABLE_INDICATOR = 'DomainName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|SimpleDb
     */
    protected $service = null;

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
            $_result = $this->service->getConnection()->domainMetadata( array(static::TABLE_INDICATOR => $_name) );

            // The result of an operation can be used like an array
            $_out = $_result->toArray();
            $_out['name'] = $_name;
            $_out[static::TABLE_INDICATOR] = $_name;
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
                $properties
            );
            $_result = $this->service->getConnection()->createDomain( $_properties );

            $_out = array_merge( array('name' => $table, static::TABLE_INDICATOR => $table), $_result->toArray() );

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

        throw new BadRequestException( "Update table operation is not supported for this service." );
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
            $_result = $this->service->getConnection()->deleteDomain( array(static::TABLE_INDICATOR => $_name) );

            return array_merge( array('name' => $_name, static::TABLE_INDICATOR => $_name), $_result->toArray() );
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to delete table '$_name'.\n{$_ex->getMessage()}" );
        }
    }
}