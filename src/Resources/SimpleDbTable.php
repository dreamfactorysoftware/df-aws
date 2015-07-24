<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseDbTableResource;
use DreamFactory\Core\Aws\Services\SimpleDb;
use DreamFactory\Core\Utility\DbUtilities;

class SimpleDbTable extends BaseDbTableResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const TABLE_INDICATOR = 'DomainName';
    /**
     * Default record identifier field
     */
    const DEFAULT_ID_FIELD = 'id';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|SimpleDb
     */
    protected $service = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@InheritDoc}
     */
    public function correctTableName(&$name)
    {
        static $existing = null;

        if (!$existing) {
            $existing = $this->service->getTables();
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
    public function retrieveRecordsByFilter($table, $filter = null, $params = array(), $extras = array())
    {
        $idField = ArrayUtils::get($extras, 'id_field', static::DEFAULT_ID_FIELD);
        $fields = ArrayUtils::get($extras, 'fields');
        $ssFilters = ArrayUtils::get($extras, 'ss_filters');

        $fields = static::buildAttributesToGet($fields);

        $select = 'select ';
        $select .= (empty($fields)) ? '*' : $fields;
        $select .= ' from ' . $table;

        $parsedFilter = static::buildCriteriaArray($filter, $params, $ssFilters);
        if (!empty($parsedFilter)) {
            $select .= ' where ' . $parsedFilter;
        }

        $order = ArrayUtils::get($extras, 'order');
        if ($order > 0) {
            $select .= ' order by ' . $order;
        }

        $limit = ArrayUtils::get($extras, 'limit');
        if ($limit > 0) {
            $select .= ' limit ' . $limit;
        }

        try {
            $result =
                $this->service->getConnection()->select(array(
                    'SelectExpression' => $select,
                    'ConsistentRead'   => true
                ));
            $items = ArrayUtils::clean($result['Items']);

            $out = array();
            foreach ($items as $item) {
                $attributes = ArrayUtils::get($item, 'Attributes');
                $name = ArrayUtils::get($item, $idField);
                $out[] = array_merge(
                    static::unformatAttributes($attributes),
                    array($idField => $name)
                );
            }

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter records from '$table'.\n{$ex->getMessage()}");
        }
    }

    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        if (empty($requested_fields)) {
            $requested_fields = array(static::DEFAULT_ID_FIELD); // can only be this
            $ids = array(
                array('name' => static::DEFAULT_ID_FIELD, 'type' => 'string', 'required' => true),
            );
        } else {
            $ids = array(
                array('name' => $requested_fields, 'type' => 'string', 'required' => true),
            );
        }

        return $ids;
    }

    /**
     * @param array $record
     * @param array $fields_info
     * @param array $filter_info
     * @param bool  $for_update
     * @param array $old_record
     *
     * @return array
     * @throws \Exception
     */
    protected function parseRecord($record, $fields_info, $filter_info = null, $for_update = false, $old_record = null)
    {
//        $record = DataFormat::arrayKeyLower( $record );
        $parsed = (empty($fields_info)) ? $record : array();
        if (!empty($fields_info)) {
            $keys = array_keys($record);
            $values = array_values($record);
            foreach ($fields_info as $fieldInfo) {
//            $name = strtolower( ArrayUtils::get( $field_info, 'name', '' ) );
                $name = ArrayUtils::get($fieldInfo, 'name', '');
                $type = ArrayUtils::get($fieldInfo, 'type');
                $pos = array_search($name, $keys);
                if (false !== $pos) {
                    $fieldVal = ArrayUtils::get($values, $pos);
                    // due to conversion from XML to array, null or empty xml elements have the array value of an empty array
                    if (is_array($fieldVal) && empty($fieldVal)) {
                        $fieldVal = null;
                    }

                    /** validations **/

                    $validations = ArrayUtils::get($fieldInfo, 'validation');

                    if (!static::validateFieldValue($name, $fieldVal, $validations, $for_update, $fieldInfo)) {
                        unset($keys[$pos]);
                        unset($values[$pos]);
                        continue;
                    }

                    $parsed[$name] = $fieldVal;
                    unset($keys[$pos]);
                    unset($values[$pos]);
                }

                // add or override for specific fields
                switch ($type) {
                    case 'timestamp_on_create':
                        if (!$for_update) {
                            $parsed[$name] = new \MongoDate();
                        }
                        break;
                    case 'timestamp_on_update':
                        $parsed[$name] = new \MongoDate();
                        break;
                    case 'user_id_on_create':
                        if (!$for_update) {
                            $userId = 1;//Session::getCurrentUserId();
                            if (isset($userId)) {
                                $parsed[$name] = $userId;
                            }
                        }
                        break;
                    case 'user_id_on_update':
                        $userId = 1;//Session::getCurrentUserId();
                        if (isset($userId)) {
                            $parsed[$name] = $userId;
                        }
                        break;
                }
            }
        }

        if (!empty($filter_info)) {
            $this->validateRecord($parsed, $filter_info, $for_update, $old_record);
        }

        return $parsed;
    }

    protected static function formatValue($value)
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            return '#DFJ#' . json_encode($value);
        }
        if (is_bool($value)) {
            return '#DFB#' . strval($value);
        }
        if (is_float($value)) {
            return '#DFF#' . strval($value);
        }
        if (is_int($value)) {
            return '#DFI#' . strval($value);
        }

        return $value;
    }

    protected static function unformatValue($value)
    {
        if (0 == substr_compare($value, '#DFJ#', 0, 5)) {
            return json_decode(substr($value, 5));
        }
        if (0 == substr_compare($value, '#DFB#', 0, 5)) {
            return (bool)substr($value, 5);
        }
        if (0 == substr_compare($value, '#DFF#', 0, 5)) {
            return floatval(substr($value, 5));
        }
        if (0 == substr_compare($value, '#DFI#', 0, 5)) {
            return intval(substr($value, 5));
        }

        return $value;
    }

    /**
     * @param array $record
     * @param bool  $replace
     *
     * @return array
     */
    protected static function formatAttributes($record, $replace = false)
    {
        $out = array();
        if (!empty($record)) {
            foreach ($record as $name => $value) {
                if (ArrayUtils::isArrayNumeric($value)) {
                    foreach ($value as $key => $part) {
                        $part = static::formatValue($part);
                        if (0 == $key) {
                            $out[] = array('Name' => $name, 'Value' => $part, 'Replace' => $replace);
                        } else {
                            $out[] = array('Name' => $name, 'Value' => $part);
                        }
                    }
                } else {
                    $value = static::formatValue($value);
                    $out[] = array('Name' => $name, 'Value' => $value, 'Replace' => $replace);
                }
            }
        }

        return $out;
    }

    /**
     * @param array $record
     *
     * @return array
     */
    protected static function unformatAttributes($record)
    {
        $out = array();
        if (!empty($record)) {
            foreach ($record as $attribute) {
                $name = ArrayUtils::get($attribute, 'Name');
                if (empty($name)) {
                    continue;
                }

                $value = ArrayUtils::get($attribute, 'Value');
                if (isset($out[$name])) {
                    $temp = $out[$name];
                    if (is_array($temp)) {
                        $temp[] = static::unformatValue($value);
                        $value = $temp;
                    } else {
                        $value = array($temp, static::unformatValue($value));
                    }
                } else {
                    $value = static::unformatValue($value);
                }
                $out[$name] = $value;
            }
        }

        return $out;
    }

    protected static function buildAttributesToGet($fields = null, $id_fields = null)
    {
        if ('*' == $fields) {
            return null;
        }
        if (empty($fields)) {
            if (empty($id_fields)) {
                return null;
            }
            if (!is_array($id_fields)) {
                $id_fields = array_map('trim', explode(',', trim($id_fields, ',')));
            }

            return $id_fields;
        }

        if (!is_array($fields)) {
            $fields = array_map('trim', explode(',', trim($fields, ',')));
        }

        return $fields;
    }

    protected static function buildCriteriaArray($filter, $params = null, $ss_filters = null)
    {
        // interpret any parameter values as lookups
        $params = static::interpretRecordValues($params);

        // build filter array if necessary, add server-side filters if necessary
        $criteria = static::parseFilter($filter, $params);
        $serverCriteria = static::buildSSFilterArray($ss_filters);
        if (!empty($serverCriteria)) {
            $criteria =
                (!empty($criteria)) ? '(' . $serverCriteria . ') AND (' . $criteria . ')' : $serverCriteria;
        }

        return $criteria;
    }

    protected static function buildSSFilterArray($ss_filters)
    {
        if (empty($ss_filters)) {
            return '';
        }

        // build the server side criteria
        $filters = ArrayUtils::get($ss_filters, 'filters');
        if (empty($filters)) {
            return '';
        }

        $combiner = ArrayUtils::get($ss_filters, 'filter_op', 'and');
        switch (strtoupper($combiner)) {
            case 'AND':
            case 'OR':
                break;
            default:
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
        }

        $criteria = '';
        foreach ($filters as $filter) {
            $name = ArrayUtils::get($filter, 'name');
            $op = ArrayUtils::get($filter, 'operator');
            if (empty($name) || empty($op)) {
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
            }

            $value = ArrayUtils::get($filter, 'value');
            $value = static::interpretFilterValue($value);

            $temp = static::parseFilter("$name $op $value");
            if (!empty($criteria)) {
                $criteria .= " $combiner ";
            }
            $criteria .= $temp;
        }

        return $criteria;
    }

    /**
     * @param string|array $filter Filter for querying records by
     * @param null         $params
     *
     * @throws BadRequestException
     * @return array
     */
    protected static function parseFilter($filter, $params = null)
    {
        if (empty($filter)) {
            return $filter;
        }

        if (is_array($filter)) {
            throw new BadRequestException('Filtering in array format is not currently supported on SimpleDb.');
        }

//        Session::replaceLookups( $filter );

        // handle logical operators first
        $search = array(' || ', ' && ');
        $replace = array(' or ', ' and ');
        $filter = trim(str_ireplace($search, $replace, $filter));

        // the rest should be comparison operators
        $search = array(' eq ', ' ne ', ' gte ', ' lte ', ' gt ', ' lt ');
        $replace = array(' = ', ' != ', ' >= ', ' <= ', ' > ', ' < ');
        $filter = trim(str_ireplace($search, $replace, $filter));

        // check for x = null
        $filter = str_ireplace(' = null', ' is null', $filter);
        // check for x != null
        $filter = str_ireplace(' != null', ' is not null', $filter);

        return $filter;
    }

    /**
     * {@inheritdoc}
     */
    protected function initTransaction($handle = null)
    {
        return parent::initTransaction($handle);
    }

    /**
     * {@inheritdoc}
     */
    protected function addToTransaction(
        $record = null,
        $id = null,
        $extras = null,
        $rollback = false,
        $continue = false,
        $single = false
    ){
        $ssFilters = ArrayUtils::get($extras, 'ss_filters');
        $fields = ArrayUtils::get($extras, 'fields');
        $fieldsInfo = ArrayUtils::get($extras, 'fields_info');
        $idsInfo = ArrayUtils::get($extras, 'ids_info');
        $idFields = ArrayUtils::get($extras, 'id_fields');
        $updates = ArrayUtils::get($extras, 'updates');

        $out = array();
        switch ($this->getAction()) {
            case Verbs::POST:
                $parsed = $this->parseRecord($record, $fieldsInfo, $ssFilters);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $native = $this->formatAttributes($parsed);

                /*$result = */
                $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $native,
                        'Expected'              => array($idFields[0] => array('Exists' => false))
                    )
                );

                if ($rollback) {
                    $this->addToRollback($id);
                }

                $out = static::cleanRecord($record, $fields, $idFields);
                break;
            case Verbs::PUT:
                if (!empty($updates)) {
                    // only update by full records can use batching
                    $updates[$idFields[0]] = $id;
                    $record = $updates;
                }

                $parsed = $this->parseRecord($record, $fieldsInfo, $ssFilters, true);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $native = $this->formatAttributes($parsed, true);
//                $batched = array( 'Name' => $id, 'Attributes' => $native );

                if (!$continue && !$rollback) {
                    $batched = array('Name' => $id, 'Attributes' => $native);

                    return parent::addToTransaction($batched, $id);
                }

                $result = $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $native,
                    )
                );

                if ($rollback) {
                    $temp = ArrayUtils::get($result, 'Attributes');
                    if (!empty($temp)) {
                        $this->addToRollback($temp);
                    }
                }

                $out = static::cleanRecord($record, $fields, $idFields);
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                if (!empty($updates)) {
                    $updates[$idFields[0]] = $id;
                    $record = $updates;
                }

                $parsed = $this->parseRecord($record, $fieldsInfo, $ssFilters, true);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $native = $this->formatAttributes($parsed, true);

                $result = $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $native,
                    )
                );

                if ($rollback) {
                    $old = ArrayUtils::get($result, 'Attributes', array());
                    $this->addToRollback($old);
                }

                $out = static::cleanRecord($record, $fields, $idFields);
                break;

            case Verbs::DELETE:
                if (!$continue && !$rollback) {
                    return parent::addToTransaction(null, $id);
                }

                $result = $this->service->getConnection()->deleteAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'ItemName'              => $id
                    )
                );

                $temp = ArrayUtils::get($result, 'Attributes', array());

                if ($rollback) {
                    $this->addToRollback($temp);
                }

                $temp = $this->unformatAttributes($temp);
                $out = static::cleanRecord($temp, $fields, $idFields);
                break;

            case Verbs::GET:
                $scanProperties = array(
                    static::TABLE_INDICATOR => $this->transactionTable,
                    'ItemName'              => $id,
                    'ConsistentRead'        => true,
                );

                $fields = static::buildAttributesToGet($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['AttributeNames'] = $fields;
                }

                $result = $this->service->getConnection()->getAttributes($scanProperties);

                $out = array_merge(
                    static::unformatAttributes($result['Attributes']),
                    array($idFields[0] => $id)
                );
                break;
            default:
                break;
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction($extras = null)
    {
        if (empty($this->batchRecords) && empty($this->batchIds)) {
            return null;
        }

        $ssFilters = ArrayUtils::get($extras, 'ss_filters');
        $fields = ArrayUtils::get($extras, 'fields');
        $requireMore = ArrayUtils::get($extras, 'require_more');
        $idsInfo = ArrayUtils::get($extras, 'ids_info');
        $idFields = ArrayUtils::get($extras, 'id_fields');

        $out = array();
        switch ($this->getAction()) {
            case Verbs::POST:
                $result = $this->service->getConnection()->batchPutAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Items'                 => $this->batchRecords,
                    )
                );

                $out = static::cleanRecords($this->batchRecords, $fields, $idFields);
                break;

            case Verbs::PUT:
                $result = $this->service->getConnection()->batchPutAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Items'                 => $this->batchRecords,
                    )
                );

                $out = static::cleanRecords($this->batchRecords, $fields, $idFields);
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                throw new BadRequestException('Batch operation not supported for patch.');
                break;

            case Verbs::DELETE:
                if ($requireMore) {
                    $fields = static::buildAttributesToGet($fields);

                    $select = 'select ';
                    $select .= (empty($fields)) ? '*' : $fields;
                    $select .= ' from ' . $this->transactionTable;

                    $filter = "itemName() in ('" . implode("','", $this->batchIds) . "')";
                    $parsedFilter = static::buildCriteriaArray($filter, null, $ssFilters);
                    if (!empty($parsedFilter)) {
                        $select .= ' where ' . $parsedFilter;
                    }

                    $result =
                        $this->service->getConnection()->select(array(
                            'SelectExpression' => $select,
                            'ConsistentRead'   => true
                        ));
                    $items = ArrayUtils::clean($result['Items']);

                    $out = array();
                    foreach ($items as $item) {
                        $attributes = ArrayUtils::get($item, 'Attributes');
                        $name = ArrayUtils::get($item, static::DEFAULT_ID_FIELD);
                        $out[] = array_merge(
                            static::unformatAttributes($attributes),
                            array($idFields[0] => $name)
                        );
                    }
                } else {
                    $out = static::cleanRecords($this->batchRecords, $fields, $idFields);
                }

                $items = array();
                foreach ($this->batchIds as $id) {
                    $items[] = array('Name' => $id);
                }
                /*$result = */
                $this->service->getConnection()->batchDeleteAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Items'                 => $items
                    )
                );

                // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                break;

            case Verbs::GET:
                $fields = static::buildAttributesToGet($fields);

                $select = 'select ';
                $select .= (empty($fields)) ? '*' : $fields;
                $select .= ' from ' . $this->transactionTable;

                $filter = "itemName() in ('" . implode("','", $this->batchIds) . "')";
                $parsedFilter = static::buildCriteriaArray($filter, null, $ssFilters);
                if (!empty($parsedFilter)) {
                    $select .= ' where ' . $parsedFilter;
                }

                $result =
                    $this->service->getConnection()->select(array(
                        'SelectExpression' => $select,
                        'ConsistentRead'   => true
                    ));
                $items = ArrayUtils::clean($result['Items']);

                $out = array();
                foreach ($items as $item) {
                    $attributes = ArrayUtils::get($item, 'Attributes');
                    $name = ArrayUtils::get($item, 'Name');
                    $out[] = array_merge(
                        static::unformatAttributes($attributes),
                        array($idFields[0] => $name)
                    );
                }
                break;
            default:
                break;
        }

        $this->batchIds = array();
        $this->batchRecords = array();

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    protected function addToRollback($record)
    {
        return parent::addToRollback($record);
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if (!empty($this->rollbackRecords)) {
            switch ($this->getAction()) {
                case Verbs::POST:

                    /* $result = */
                    $this->service->getConnection()->batchDeleteAttributes(
                        array(
                            static::TABLE_INDICATOR => $this->transactionTable,
                            'Items'                 => $this->rollbackRecords
                        )
                    );
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $requests = array();
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = array('PutRequest' => array('Item' => $item));
                    }

                    $this->service->getConnection()->batchPutAttributes(
                        array(
                            static::TABLE_INDICATOR => $this->transactionTable,
                            'Items'                 => $this->batchRecords,
                        )
                    );

                    // todo check $result['UnprocessedItems'] for 'PutRequest'
                    break;

                default:
                    break;
            }

            $this->rollbackRecords = array();
        }

        return true;
    }
}