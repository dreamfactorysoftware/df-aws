<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\DynamoDb\DynamoDbClient;
use DreamFactory\Core\Aws\Resources\DynamoDbSchema;
use DreamFactory\Core\Aws\Resources\DynamoDbTable;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseNoSqlDbService;
use DreamFactory\Core\Utility\Session;
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

    /**
     *
     */
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
    public function __construct($settings = [])
    {
        parent::__construct($settings);

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config'));
        //  Replace any private lookups
        Session::replaceLookups($config, true);
        // statically assign our supported version
        $config['version'] = '2012-08-10';
        if (isset($config['key']))
        {
            $config['credentials']['key'] = $config['key'];
        }
        if (isset($config['secret']))
        {
            $config['credentials']['secret'] = $config['secret'];
        }

        // set up a default table schema
        $parameters = ArrayUtils::clean(ArrayUtils::get($config, 'parameters'));
        Session::replaceLookups($parameters);
        if (null !== ($table = ArrayUtils::get($parameters, 'default_create_table'))) {
            $this->defaultCreateTable = $table;
        }

        try {
            $this->dbConn = new DynamoDbClient($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS DynamoDb Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->dbConn = null;
        } catch (\Exception $ex) {
            error_log("Failed to disconnect from database.\n{$ex->getMessage()}");
        }
    }

    /**
     * @throws \Exception
     * @return DynamoDbClient
     */
    public function getConnection()
    {
        if (!isset($this->dbConn)) {
            throw new InternalServerErrorException('Database connection has not been initialized.');
        }

        return $this->dbConn;
    }

    /**
     * @return array
     */
    public function getTables()
    {
        $out = [];
        do {
            $result = $this->dbConn->listTables(
                [
                    'Limit'                   => 100, // arbitrary limit
                    'ExclusiveStartTableName' => isset($result) ? $result['LastEvaluatedTableName'] : null
                ]
            );

            $out = array_merge($out, $result['TableNames']);
        } while ($result['LastEvaluatedTableName']);

        return $out;
    }

    /**
     * @param string $name
     *
     * @return string
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function correctTableName(&$name)
    {
        static $existing = null;

        if (!$existing) {
            $existing = $this->getTables();
        }

        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        if (false === array_search($name, $existing)) {
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
        } catch (NotFoundException $ex) {
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

            throw $ex;
        }
    }
}