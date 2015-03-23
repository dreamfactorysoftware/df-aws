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

namespace DreamFactory\Rave\Aws\Services;


use Aws\DynamoDb\DynamoDbClient;
use DreamFactory\Rave\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Rave\Aws\Resources\Schema;
use DreamFactory\Rave\Aws\Resources\Table;
use DreamFactory\Rave\Contracts\ServiceResponseInterface;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
use DreamFactory\Rave\Resources\BaseRestResource;
use DreamFactory\Rave\Services\BaseNoSqlDbService;
use DreamFactory\Library\Utility\ArrayUtils;

/**
 * DynamoDb
 *
 * A service to handle DynamoDb NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class DynamoDb extends BaseNoSqlDbService
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const CLIENT_NAME = 'DynamoDb';

    const TABLE_INDICATOR = 'TableName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var DynamoDbClient|null
     */
    protected $dbConn = null;
    /**
     * @var array
     */
    protected $resources = [
        Schema::RESOURCE_NAME          => [
            'name'       => Schema::RESOURCE_NAME,
            'class_name' => 'DreamFactory\\Rave\\Aws\\Resources\\Schema',
            'label'      => 'Schema',
        ],
        Table::RESOURCE_NAME           => [
            'name'       => Table::RESOURCE_NAME,
            'class_name' => 'DreamFactory\\Rave\\Aws\\Resources\\Table',
            'label'      => 'Table',
        ],
    ];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new DynamoDb
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct( $settings = array() )
    {
        parent::__construct( $settings );

        $config = ArrayUtils::clean( ArrayUtils::get( $settings, 'config' ) );
        AwsSvcUtilities::updateCredentials( $config, true );

        // set up a default table schema
        $parameters = ArrayUtils::clean( ArrayUtils::get( $config, 'parameters' ));
        //Session::replaceLookups( $_parameters );
        if ( null !== ( $_table = ArrayUtils::get( $parameters, 'default_create_table' ) ) )
        {
            $this->defaultCreateTable = $_table;
        }

        $this->dbConn = AwsSvcUtilities::createClient( $config, static::CLIENT_NAME );
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try
        {
            $this->dbConn = null;
        }
        catch ( \Exception $_ex )
        {
            error_log( "Failed to disconnect from database.\n{$_ex->getMessage()}" );
        }
    }

    /**
     * @throws \Exception
     */
    public function getConnection()
    {
        if ( !isset( $this->dbConn ) )
        {
            throw new InternalServerErrorException( 'Database connection has not been initialized.' );
        }

        return $this->dbConn;
    }

    public function getTables()
    {
        $out = array();
        do
        {
            $result = $this->dbConn->listTables(
                array(
                    'Limit'                   => 100, // arbitrary limit
                    'ExclusiveStartTableName' => isset( $_result ) ? $_result['LastEvaluatedTableName'] : null
                )
            );

            $out = array_merge( $out, $result['TableNames'] );
        }
        while ( $result['LastEvaluatedTableName'] );

        return $out;
    }

    // REST service implementation

    /**
     * @param string $name
     *
     * @return string
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function correctTableName( &$name )
    {
        static $_existing = null;

        if ( !$_existing )
        {
            $_existing = $this->getTables();
        }

        if ( empty( $name ) )
        {
            throw new BadRequestException( 'Table name can not be empty.' );
        }

        if ( false === array_search( $name, $_existing ) )
        {
            throw new NotFoundException( "Table '$name' not found." );
        }

        return $name;
    }

    /**
     * @param string $main   Main resource or empty for service
     * @param string $sub    Subtending resources if applicable
     * @param string $action Action to validate permission
     */
    protected function validateResourceAccess( $main, $sub, $action )
    {
        if ( !empty( $main ) )
        {
            $_resource = rtrim( $main, '/' ) . '/';
            switch ( $main )
            {
                case Schema::RESOURCE_NAME:
                case Table::RESOURCE_NAME:
                    if ( !empty( $sub ) )
                    {
                        $_resource .= $sub;
                    }
                    break;
            }

            $this->checkPermission( $action, $_resource );

            return;
        }

        parent::validateResourceAccess( $main, $sub, $action );
    }

    /**
     * @param BaseRestResource $class
     * @param array            $info
     *
     * @return mixed
     */
    protected function instantiateResource( $class, $info = [ ] )
    {
        return new $class( $this, $info );
    }

    /**
     * {@InheritDoc}
     */
    protected function handleResource()
    {
        try
        {
            return parent::handleResource();
        }
        catch ( NotFoundException $_ex )
        {
            // If version 1.x, the resource could be a table
//            if ($this->request->getApiVersion())
//            {
//                $resource = $this->instantiateResource( 'DreamFactory\\Rave\\MongoDb\\Resources\\Table', [ 'name' => $this->resource ] );
//                $newPath = $this->resourceArray;
//                array_shift( $newPath );
//                $newPath = implode( '/', $newPath );
//
//                return $resource->handleRequest( $this->request, $newPath, $this->outputFormat );
//            }

            throw $_ex;
        }
    }

    /**
     * @return array
     */
    protected function getResources()
    {
        return $this->resources;
    }

    // REST service implementation

    /**
     * {@inheritdoc}
     */
    public function listResources( $include_properties = null )
    {
        if ( !$this->request->queryBool( 'as_access_components' ) )
        {
            return parent::listResources( $include_properties );
        }

        $_resources = [ ];

//        $refresh = $this->request->queryBool( 'refresh' );

        $_name = Schema::RESOURCE_NAME . '/';
        $_access = $this->getPermissions( $_name );
        if ( !empty( $_access ) )
        {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $_result = $this->getTables();
        foreach ( $_result as $_name )
        {
            $_name = Schema::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions( $_name );
            if ( !empty( $_access ) )
            {
                $_resources[] = $_name;
            }
        }

        $_name = Table::RESOURCE_NAME . '/';
        $_access = $this->getPermissions( $_name );
        if ( !empty( $_access ) )
        {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        foreach ( $_result as $_name )
        {
            $_name = Table::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions( $_name );
            if ( !empty( $_access ) )
            {
                $_resources[] = $_name;
            }
        }

        return array( 'resource' => $_resources );
    }

    /**
     * @return ServiceResponseInterface
     */
//    protected function respond()
//    {
//        if ( Verbs::POST === $this->getRequestedAction() )
//        {
//            switch ( $this->resource )
//            {
//                case Table::RESOURCE_NAME:
//                case Schema::RESOURCE_NAME:
//                    if ( !( $this->response instanceof ServiceResponseInterface ) )
//                    {
//                        $this->response = ResponseFactory::create( $this->response, $this->outputFormat, ServiceResponseInterface::HTTP_CREATED );
//                    }
//                    break;
//            }
//        }
//
//        parent::respond();
//    }

}