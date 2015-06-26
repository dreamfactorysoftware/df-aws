<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\DynamoDb\DynamoDbClient;
use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Core\Aws\Resources\DynamoDbSchema;
use DreamFactory\Core\Aws\Resources\DynamoDbTable;
use DreamFactory\Core\Contracts\ServiceResponseInterface;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseNoSqlDbService;
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
        DynamoDbSchema::RESOURCE_NAME => [
            'name'       => DynamoDbSchema::RESOURCE_NAME,
            'class_name' => DynamoDbSchema::class,
            'label'      => 'Schema',
        ],
        DynamoDbTable::RESOURCE_NAME  => [
            'name'       => DynamoDbTable::RESOURCE_NAME,
            'class_name' => DynamoDbTable::class,
            'label'      => 'Table',
        ],
    ];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new DynamoDb
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = array())
    {
        parent::__construct($settings);

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config'));
        AwsSvcUtilities::updateCredentials($config, true);

        // set up a default table schema
        $parameters = ArrayUtils::clean(ArrayUtils::get($config, 'parameters'));
        //Session::replaceLookups( $_parameters );
        if (null !== ($_table = ArrayUtils::get($parameters, 'default_create_table'))) {
            $this->defaultCreateTable = $_table;
        }

        $this->dbConn = AwsSvcUtilities::createClient($config, static::CLIENT_NAME);
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->dbConn = null;
        } catch (\Exception $_ex) {
            error_log("Failed to disconnect from database.\n{$_ex->getMessage()}");
        }
    }

    /**
     * @throws \Exception
     */
    public function getConnection()
    {
        if (!isset($this->dbConn)) {
            throw new InternalServerErrorException('Database connection has not been initialized.');
        }

        return $this->dbConn;
    }

    public function getTables()
    {
        $out = array();
        do {
            $result = $this->dbConn->listTables(
                array(
                    'Limit'                   => 100, // arbitrary limit
                    'ExclusiveStartTableName' => isset($_result) ? $_result['LastEvaluatedTableName'] : null
                )
            );

            $out = array_merge($out, $result['TableNames']);
        } while ($result['LastEvaluatedTableName']);

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
    public function correctTableName(&$name)
    {
        static $_existing = null;

        if (!$_existing) {
            $_existing = $this->getTables();
        }

        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        if (false === array_search($name, $_existing)) {
            throw new NotFoundException("Table '$name' not found.");
        }

        return $name;
    }

    /**
     * {@InheritDoc}
     */
    protected function handleResource(array $resources)
    {
        try {
            return parent::handleResource($resources);
        } catch (NotFoundException $_ex) {
            // If version 1.x, the resource could be a table
//            if ($this->request->getApiVersion())
//            {
//                $resource = $this->instantiateResource( Table::class, [ 'name' => $this->resource ] );
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
    public function listResources($fields = null)
    {
        if (!$this->request->getParameterAsBool('as_access_components')) {
            return parent::listResources($fields);
        }

        $_resources = [];

//        $refresh = $this->request->queryBool( 'refresh' );

        $_name = DynamoDbSchema::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $_result = $this->getTables();
        foreach ($_result as $_name) {
            $_name = DynamoDbSchema::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        $_name = DynamoDbTable::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        foreach ($_result as $_name) {
            $_name = DynamoDbTable::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        return ['resource' => $_resources];
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