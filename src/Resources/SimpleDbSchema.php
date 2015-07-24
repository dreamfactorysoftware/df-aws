<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Resources\BaseNoSqlDbSchemaResource;
use DreamFactory\Core\Aws\Services\SimpleDb;
use DreamFactory\Core\Utility\DbUtilities;

class SimpleDbSchema extends BaseNoSqlDbSchemaResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const TABLE_INDICATOR = 'DomainName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|SimpleDb
     */
    protected $service = null;

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }
//        $refresh = $this->request->queryBool('refresh');

        $names = $this->service->getTables();

        $extras =
            DbUtilities::getSchemaExtrasForTables($this->service->getServiceId(), $names, false, 'table,label,plural');

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
    public function describeTable($table, $refresh = true)
    {
        $name =
            (is_array($table)) ? ArrayUtils::get($table, 'name', ArrayUtils::get($table, static::TABLE_INDICATOR))
                : $table;
        try {
            $result = $this->service->getConnection()->domainMetadata(array(static::TABLE_INDICATOR => $name));

            // The result of an operation can be used like an array
            $out = $result->toArray();
            $out['name'] = $name;
            $out[static::TABLE_INDICATOR] = $name;
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
                $properties
            );
            $result = $this->service->getConnection()->createDomain($properties);

            $out = array_merge(array('name' => $table, static::TABLE_INDICATOR => $table), $result->toArray());

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

        throw new BadRequestException("Update table operation is not supported for this service.");
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
            $result = $this->service->getConnection()->deleteDomain(array(static::TABLE_INDICATOR => $name));

            return array_merge(array('name' => $name, static::TABLE_INDICATOR => $name), $result->toArray());
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to delete table '$name'.\n{$ex->getMessage()}");
        }
    }
}