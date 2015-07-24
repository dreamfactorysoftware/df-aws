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
    protected $parent = null;
    /**
     * @var array
     */
    protected $defaultCreateTable = array(
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
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }
//        $refresh = $this->request->queryBool('refresh');

        $names = $this->parent->getTables();

        $extras =
            DbUtilities::getSchemaExtrasForTables($this->parent->getServiceId(), $names, false, 'table,label,plural');

        $tables = [];
        foreach ($names as $name) {
            $label = '';
            $plural = '';
            foreach ($extras as $each) {
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

            $tables[] = ['name' => $name, 'label' => $label, 'plural' => $plural];
        }

        return $tables;
    }

    /**
     * {@inheritdoc}
     */
    public function listAccessComponents($schema = null, $refresh = false)
    {
        $output = [];
        $result = $this->parent->getTables();
        foreach ($result as $name) {
            $output[] = static::RESOURCE_NAME . '/' . $name;
        }

        return $output;
    }

    /**
     * {@inheritdoc}
     */
    public function describeTable($table, $refresh = true)
    {
        $name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        try {
            $result = $this->parent->getConnection()->describeTable(array(static::TABLE_INDICATOR => $name));

            // The result of an operation can be used like an array
            $out = $result['Table'];
            $out['name'] = $name;
            $out['access'] = $this->getPermissions($name);

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to get table properties for table '$name'.\n{$ex->getMessage(
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
            $properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $this->defaultCreateTable,
                $properties
            );
            $result = $this->parent->getConnection()->createTable($properties);

            // Wait until the table is created and active
            $this->parent->getConnection()->waitUntilTableExists(array(static::TABLE_INDICATOR => $table));

            $out = array_merge(array('name' => $table), $result['TableDescription']);

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to create table '$table'.\n{$ex->getMessage()}");
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
            $properties = array_merge(
                array(static::TABLE_INDICATOR => $table),
                $properties
            );
            $result = $this->parent->getConnection()->updateTable($properties);

            // Wait until the table is active again after updating
            $this->parent->getConnection()->waitUntilTableExists(array(static::TABLE_INDICATOR => $table));

            return array_merge(array('name' => $table), $result['TableDescription']);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to update table '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTable($table, $check_empty = false)
    {
        $name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        try {
            $result = $this->parent->getConnection()->deleteTable(array(static::TABLE_INDICATOR => $name));

            // Wait until the table is truly gone
            $this->parent->getConnection()->waitUntilTableNotExists(array(static::TABLE_INDICATOR => $name));

            return array_merge(array('name' => $name), $result['TableDescription']);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete table '$name'.\n{$ex->getMessage()}");
        }
    }
}