<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\DynamoDb\DynamoDbClient;
use DreamFactory\Core\Aws\Resources\DynamoDbSchema;
use DreamFactory\Core\Aws\Resources\DynamoDbTable;
use DreamFactory\Core\Components\DbSchemaExtras;
use DreamFactory\Core\Database\TableNameSchema;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
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
    use DbSchemaExtras;

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
    protected $tableNames = [];
    /**
     * @var array
     */
    protected $tables = [];
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

    public function getTableNames($schema = null, $refresh = false, $use_alias = false)
    {
        if ($refresh ||
            (empty($this->tableNames) &&
                (null === $this->tableNames = $this->getFromCache('table_names')))
        ) {
            /** @type TableNameSchema[] $names */
            $names = [];
            $tables = $this->getTables();
            foreach ($tables as $table) {
                $names[strtolower($table)] = new TableNameSchema($table);
            }
            // merge db extras
            if (!empty($extrasEntries = $this->getSchemaExtrasForTables($tables, false))) {
                foreach ($extrasEntries as $extras) {
                    if (!empty($extraName = strtolower(strval($extras['table'])))) {
                        if (array_key_exists($extraName, $tables)) {
                            $names[$extraName]->fill($extras);
                        }
                    }
                }
            }
            $this->tableNames = $names;
            $this->addToCache('table_names', $this->tableNames, true);
        }

        return $this->tableNames;
    }

    public function refreshTableCache()
    {
        $this->removeFromCache('table_names');
        $this->tableNames = [];
        $this->tables = [];
    }
}