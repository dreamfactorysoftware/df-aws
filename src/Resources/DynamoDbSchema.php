<?php
namespace DreamFactory\Core\Aws\Resources;

use Aws\DynamoDb\Enum\KeyType;
use Aws\DynamoDb\Enum\Type;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Core\Utility\DbUtilities;

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
    public function listResources($fields = null)
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getTables();

        if (empty($fields)) {
            return ['resource' => $_names];
        }

        $_extras =
            DbUtilities::getSchemaExtrasForTables($this->service->getServiceId(), $_names, false, 'table,label,plural');

        $_tables = [];
        foreach ($_names as $name) {
            $label = '';
            $plural = '';
            foreach ($_extras as $each) {
                if (0 == strcasecmp($name, ArrayUtils::get($each, 'table', ''))) {
                    $label = ArrayUtils::get($each, 'label');
                    $plural = ArrayUtils::get($each, 'plural');
                    break;
                }
            }

            if (empty($label)) {
                $label = Inflector::camelize($name, ['_', '.'], true);
            }

            if (empty($plural)) {
                $plural = Inflector::pluralize($label);
            }

            $_tables[] = ['name' => $name, 'label' => $label, 'plural' => $plural];
        }

        return $this->makeResourceList($_tables, 'name', $fields, 'resource');
    }

    /**
     * {@inheritdoc}
     */
    public function describeTable($table, $refresh = true)
    {
        $_name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        try {
            $_result = $this->service->getConnection()->describeTable(array(static::TABLE_INDICATOR => $_name));

            // The result of an operation can be used like an array
            $_out = $_result['Table'];
            $_out['name'] = $_name;
            $_out['access'] = $this->getPermissions($_name);

            return $_out;
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to get table properties for table '$_name'.\n{$_ex->getMessage(
            )}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable($table, $properties = array(), $check_exist = false, $return_schema = false)
    {
        if (empty($table)) {
            $table = ArrayUtils::get($properties, static::TABLE_INDICATOR);
        }
        if (empty($table)) {
            throw new BadRequestException("No 'name' field in data.");
        }

        try {
            $_properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $this->_defaultCreateTable,
                $properties
            );
            $_result = $this->service->getConnection()->createTable($_properties);

            // Wait until the table is created and active
            $this->service->getConnection()->waitUntilTableExists(array(static::TABLE_INDICATOR => $table));

            $_out = array_merge(array('name' => $table), $_result['TableDescription']);

            return $_out;
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to create table '$table'.\n{$_ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable($table, $properties = array(), $allow_delete_fields = false, $return_schema = false)
    {
        if (empty($table)) {
            $table = ArrayUtils::get($properties, static::TABLE_INDICATOR);
        }
        if (empty($table)) {
            throw new BadRequestException("No 'name' field in data.");
        }

        try {
            // Update the provisioned throughput capacity of the table
            $_properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $properties
            );
            $_result = $this->service->getConnection()->updateTable($_properties);

            // Wait until the table is active again after updating
            $this->service->getConnection()->waitUntilTableExists(array(static::TABLE_INDICATOR => $table));

            return array_merge(array('name' => $table), $_result['TableDescription']);
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to update table '$table'.\n{$_ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable($table, $check_empty = false)
    {
        $_name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        if (empty($_name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $_result = $this->service->getConnection()->deleteTable(array(static::TABLE_INDICATOR => $_name));

            // Wait until the table is truly gone
            $this->service->getConnection()->waitUntilTableNotExists(array(static::TABLE_INDICATOR => $_name));

            return array_merge(array('name' => $_name), $_result['TableDescription']);
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Failed to delete table '$_name'.\n{$_ex->getMessage()}");
        }
    }
}