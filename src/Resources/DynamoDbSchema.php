<?php
namespace DreamFactory\Core\Aws\Resources;

use Aws\DynamoDb\Exception\DynamoDbException;
use DreamFactory\Core\Aws\Enums\KeyType;
use DreamFactory\Core\Aws\Enums\Type;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Core\Aws\Services\DynamoDb;

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

    /**
     * {@inheritdoc}
     */
    public function describeTable($table, $refresh = true)
    {
        $name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        try {
            $result = $this->parent->getConnection()->describeTable([static::TABLE_INDICATOR => $name]);

            // The result of an operation can be used like an array
            $out = $result['Table'];
            $out['name'] = $name;
            $out['access'] = $this->getPermissions($name);

            return $out;
        } catch (DynamoDbException $ex) {
            switch ($ex->getAwsErrorCode()){
                case 'ResourceNotFoundException':
                    throw new NotFoundException("Table not found for '$name'.");
                    break;
                default:
                    throw new BadRequestException($ex->getMessage());
            }
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to get table properties for table '$name'.\n{$ex->getMessage(
            )}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createTable($table, $properties = [], $check_exist = false, $return_schema = false)
    {
        if (empty($table)) {
            $table = ArrayUtils::get($properties, static::TABLE_INDICATOR);
        }
        if (empty($table)) {
            throw new BadRequestException("No 'name' field in data.");
        }

        try {
            $properties = array_merge(
                [static::TABLE_INDICATOR => $table],
                $this->defaultCreateTable,
                $properties
            );
            $result = $this->parent->getConnection()->createTable($properties);

            // Wait until the table is created and active
            $this->parent->getConnection()->waitUntil('TableExists', [static::TABLE_INDICATOR => $table]);
            $this->refreshCachedTables();

            return array_merge(['name' => $table], $result['TableDescription']);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to create table '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function updateTable($table, $properties = [], $allow_delete_fields = false, $return_schema = false)
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
                [static::TABLE_INDICATOR => $table],
                $properties
            );
            $result = $this->parent->getConnection()->updateTable($properties);

            // Wait until the table is active again after updating
            $this->parent->getConnection()->waitUntil('TableExists', [static::TABLE_INDICATOR => $table]);
            $this->refreshCachedTables();

            return array_merge(['name' => $table], $result['TableDescription']);
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
            $result = $this->parent->getConnection()->deleteTable([static::TABLE_INDICATOR => $name]);

            // Wait until the table is truly gone
            $this->parent->getConnection()->waitUntil('TableNotExists', [static::TABLE_INDICATOR => $name]);
            $this->refreshCachedTables();

            return array_merge(['name' => $name], $result['TableDescription']);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete table '$name'.\n{$ex->getMessage()}");
        }
    }
}