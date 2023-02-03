<?php

namespace DreamFactory\Core\Aws\Database\Schema;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use DreamFactory\Core\Aws\Enums\KeyType;
use DreamFactory\Core\Aws\Enums\Type;
use DreamFactory\Core\Database\Components\Schema;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\BadRequestException;
use Illuminate\Support\Arr;

class DynamoDbSchema extends Schema
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const TABLE_INDICATOR = 'TableName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var DynamoDbClient
     */
    protected $connection;
    /**
     * @var array
     */
    protected $defaultCreateTable = [
        'AttributeDefinitions'  => [
            [
                'AttributeName' => 'id',
                'AttributeType' => Type::S
            ]
        ],
        'KeySchema'             => [
            [
                'AttributeName' => 'id',
                'KeyType'       => KeyType::HASH
            ]
        ],
        'ProvisionedThroughput' => [
            'ReadCapacityUnits'  => 10,
            'WriteCapacityUnits' => 20
        ]
    ];

    protected function getTableNames($schema = '')
    {
        $tables = [];
        $options = ['Limit' => 100]; // arbitrary limit
        do {
            if (isset($result, $result['LastEvaluatedTableName'])) {
                $options['ExclusiveStartTableName'] = $result['LastEvaluatedTableName'];
            }
            $result = $this->connection->listTables($options);
            foreach ($result['TableNames'] as $name) {
                $tables[strtolower($name)] = new TableSchema(['name' => $name]);
            }
        } while ($result['LastEvaluatedTableName']);

        return $tables;
    }

    protected static function awsTypeToType($type)
    {
        switch ($type) {
            case Type::S:
                return 'string';
            case Type::B:
                return 'binary';
            case Type::N:
                return 'integer';
            case Type::SS:
                return 'string';
            case Type::BS:
                return 'binary';
            case Type::NS:
                return 'integer';
        }

        return 'string';
    }

    /**
     * {@inheritdoc}
     */
    protected function loadTableColumns(TableSchema $table)
    {
        try {
            $result = $this->connection->describeTable([static::TABLE_INDICATOR => $table->name]);

            // The result of an operation can be used like an array
            $table->native = $result['Table'];
            $attributes = $result['Table']['AttributeDefinitions'];
            $keys = $result['Table']['KeySchema'];
            foreach ($attributes as $attribute) {
                $dbType = Arr::get($attribute, 'AttributeType', 'S');
                $type = static::extractSimpleType(static::awsTypeToType($dbType));
                $name = Arr::get($attribute, 'AttributeName');
                $column = ['name' => $name, 'type' => $type, 'db_type' => $dbType];
                $c = new ColumnSchema($column);
                $c->quotedName = $this->quoteColumnName($c->name);
                foreach ($keys as $key) {
                    if ($name === Arr::get($key, 'AttributeName')) {
                        $c->isPrimaryKey = true;
                        $table->addPrimaryKey($c->name);
                    }
                }

                $table->addColumn($c);
            }
        } catch (DynamoDbException $ex) {
            switch ($ex->getAwsErrorCode()) {
                case 'ResourceNotFoundException':
                    throw new NotFoundException("Table not found for '$table'.");
                    break;
                default:
                    throw new BadRequestException($ex->getMessage());
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable($table, $options)
    {
        if (empty($tableName = Arr::get($table, 'name'))) {
            throw new \Exception("No valid name exist in the received table schema.");
        }

        $properties = array_merge(
            [static::TABLE_INDICATOR => $tableName],
            $this->defaultCreateTable,
            (array)Arr::get($table, 'native')
        );
        $result = $this->connection->createTable($properties);

        // Wait until the table is created and active
        $this->connection->waitUntil('TableExists', [static::TABLE_INDICATOR => $tableName]);

        return array_merge(['name' => $tableName], $result['TableDescription']);
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable($tableSchema, $changes)
    {
        // Update the provisioned throughput capacity of the table
        $properties = array_merge(
            [static::TABLE_INDICATOR => $tableSchema->quotedName],
            (array)Arr::get($changes, 'native')
        );
        $result = $this->connection->updateTable($properties);

        // Wait until the table is active again after updating
        $this->connection->waitUntil('TableExists', [static::TABLE_INDICATOR => $tableSchema->quotedName]);

        return array_merge(['name' => $tableSchema->quotedName], $result['TableDescription']);
    }

    /**
     * {@inheritdoc}
     */
    public function dropTable($table)
    {
        $result = $this->connection->deleteTable([static::TABLE_INDICATOR => $table]);

        // Wait until the table is truly gone
        $this->connection->waitUntil('TableNotExists', [static::TABLE_INDICATOR => $table]);

        return array_merge(['name' => $table], $result['TableDescription']);
    }
}