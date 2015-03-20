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


use DreamFactory\Rave\Services\BaseNoSqlDbService;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Enum\ComparisonOperator;
use Aws\DynamoDb\Enum\KeyType;
use Aws\DynamoDb\Enum\ReturnValue;
use Aws\DynamoDb\Enum\Type;
use Aws\DynamoDb\Model\Attribute;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
//use DreamFactory\Rave\Resources\User\Session;
use DreamFactory\Rave\Utility\AwsSvcUtilities;
use DreamFactory\Library\Utility\ArrayUtils;

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
    protected $_dbConn = null;
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

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new AwsDynamoDbSvc
     *
     * @param array $config
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct( $config )
    {
        parent::__construct( $config );

        $_credentials = ArrayUtils::clean( ArrayUtils::get( $config, 'credentials' ) );
        AwsSvcUtilities::updateCredentials( $_credentials, true );

        // set up a default table schema
        $_parameters = ArrayUtils::get( $config, 'parameters' );
        //Session::replaceLookups( $_parameters );
        if ( null !== ( $_table = ArrayUtils::get( $_parameters, 'default_create_table' ) ) )
        {
            $this->_defaultCreateTable = $_table;
        }

        $this->_dbConn = AwsSvcUtilities::createClient( $_credentials, static::CLIENT_NAME );
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try
        {
            $this->_dbConn = null;
        }
        catch ( \Exception $_ex )
        {
            error_log( "Failed to disconnect from database.\n{$_ex->getMessage()}" );
        }
    }

    /**
     * @throws \Exception
     */
    protected function checkConnection()
    {
        if ( empty( $this->_dbConn ) )
        {
            throw new InternalServerErrorException( 'Database connection has not been initialized.' );
        }
    }

    /**
     * {@InheritDoc}
     */
    public function correctTableName( &$name )
    {
        static $_existing = null;

        if ( !$_existing )
        {
            $_existing = $this->_getTablesAsArray();
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



}