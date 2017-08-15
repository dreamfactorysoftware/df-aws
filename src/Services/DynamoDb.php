<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\DynamoDb\DynamoDbClient;
use DreamFactory\Core\Aws\Database\Schema\DynamoDbSchema;
use DreamFactory\Core\Aws\Resources\DynamoDbTable;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Database\Resources\DbSchemaResource;
use DreamFactory\Core\Database\Services\BaseDbService;
use DreamFactory\Core\Utility\Session;

/**
 * DynamoDb
 *
 * A service to handle DynamoDb NoSQL (schema-less) database services accessed through the REST API.
 */
class DynamoDb extends BaseDbService
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var array
     */
    protected static $resources = [
        DbSchemaResource::RESOURCE_NAME => [
            'name'       => DbSchemaResource::RESOURCE_NAME,
            'class_name' => DbSchemaResource::class,
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

        // statically assign our supported version
        $this->config['version'] = '2012-08-10';
        if (isset($this->config['key']))
        {
            $this->config['credentials']['key'] = $this->config['key'];
        }
        if (isset($this->config['secret']))
        {
            $this->config['credentials']['secret'] = $this->config['secret'];
        }

        // set up a default table schema
        $parameters = (array)array_get($this->config, 'parameters');
        Session::replaceLookups($parameters);
//        if (null !== ($table = array_get($parameters, 'default_create_table'))) {
//            $this->defaultCreateTable = $table;
//        }
    }

    protected function initializeConnection()
    {
        try {
            $this->dbConn = new DynamoDbClient($this->config);
            /** @noinspection PhpParamsInspection */
            $this->schema = new DynamoDbSchema($this->dbConn);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS DynamoDb Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }
    }
}