<?php
namespace DreamFactory\Core\Aws\Resources;

use Aws\DynamoDb\Enum\ComparisonOperator;
use Aws\DynamoDb\Enum\ReturnValue;
use Aws\DynamoDb\Enum\Type;
use Aws\DynamoDb\Model\Attribute;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseDbTableResource;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Core\Utility\DbUtilities;

class DynamoDbTable extends BaseDbTableResource
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
            $existing = $this->parent->getTables();
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
    public function retrieveRecordsByFilter($table, $filter = null, $params = array(), $extras = array())
    {
        $fields = ArrayUtils::get($extras, 'fields');
        $ssFilters = ArrayUtils::get($extras, 'ss_filters');

        $scanProperties = array(static::TABLE_INDICATOR => $table);

        $fields = static::buildAttributesToGet($fields);
        if (!empty($fields)) {
            $scanProperties['AttributesToGet'] = $fields;
        }

        $parsedFilter = static::buildCriteriaArray($filter, $params, $ssFilters);
        if (!empty($parsedFilter)) {
            $scanProperties['ScanFilter'] = $parsedFilter;
        }

        $limit = ArrayUtils::get($extras, 'limit');
        if ($limit > 0) {
            $scanProperties['Limit'] = $limit;
        }

        try {
            $result = $this->parent->getConnection()->scan($scanProperties);
            $items = ArrayUtils::clean($result['Items']);

            $out = array();
            foreach ($items as $item) {
                $out[] = $this->unformatAttributes($item);
            }

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter records from '$table'.\n{$ex->getMessage()}");
        }
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

    /**
     * @param array $record
     * @param bool  $for_update
     *
     * @return mixed
     */
    protected function formatAttributes($record, $for_update = false)
    {
        $format = ($for_update) ? Attribute::FORMAT_UPDATE : Attribute::FORMAT_PUT;

        return $this->parent->getConnection()->formatAttributes($record, $format);
    }

    /**
     * @param array $native
     *
     * @return array
     */
    protected function unformatAttributes($native)
    {
        $out = array();
        if (is_array($native)) {
            foreach ($native as $key => $value) {
                $out[$key] = static::unformatValue($value);
            }
        }

        return $out;
    }

    protected static function unformatValue($value)
    {
        // represented as arrays, though there is only ever one item present
        foreach ($value as $type => $actual) {
            switch ($type) {
                case Type::S:
                case Type::B:
                    return $actual;
                case Type::N:
                    if (intval($actual) == $actual) {
                        return intval($actual);
                    } else {
                        return floatval($actual);
                    }
                case Type::SS:
                case Type::BS:
                    return $actual;
                case Type::NS:
                    $out = array();
                    foreach ($actual as $item) {
                        if (intval($item) == $item) {
                            $out[] = intval($item);
                        } else {
                            $out[] = floatval($item);
                        }
                    }

                    return $out;
            }
        }

        return $value;
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

    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        $requested_fields = array();
        $result = $this->parent->getConnection()->describeTable(array(static::TABLE_INDICATOR => $table));
        $result = $result['Table'];
        $keys = ArrayUtils::get($result, 'KeySchema', array());
        $definitions = ArrayUtils::get($result, 'AttributeDefinitions', array());
        $fields = array();
        foreach ($keys as $key) {
            $name = ArrayUtils::get($key, 'AttributeName');
            $keyType = ArrayUtils::get($key, 'KeyType');
            $type = null;
            foreach ($definitions as $type) {
                if (0 == strcmp($name, ArrayUtils::get($type, 'AttributeName'))) {
                    $type = ArrayUtils::get($type, 'AttributeType');
                }
            }

            $requested_fields[] = $name;
            $fields[] = array('name' => $name, 'key_type' => $keyType, 'type' => $type, 'required' => true);
        }

        return $fields;
    }

    protected static function buildKey($ids_info, &$record, $remove = false)
    {
        $keys = array();
        foreach ($ids_info as $info) {
            $name = ArrayUtils::get($info, 'name');
            $type = ArrayUtils::get($info, 'type');
            $value = ArrayUtils::get($record, $name, null, $remove);
            if (empty($value)) {
                throw new BadRequestException("Identifying field(s) not found in record.");
            }

            switch ($type) {
                case Type::N:
                    $value = array(Type::N => strval($value));
                    break;
                default:
                    $value = array(Type::S => $value);
            }
            $keys[$name] = $value;
        }

        return $keys;
    }

    protected static function buildCriteriaArray($filter, $params = null, $ss_filters = null)
    {
        // interpret any parameter values as lookups
        $params = static::interpretRecordValues($params);

        // build filter array if necessary, add server-side filters if necessary
        if (!is_array($filter)) {
//            Session::replaceLookups( $filter );
            $criteria = static::buildFilterArray($filter, $params);
        } else {
            $criteria = $filter;
        }
        $serverCriteria = static::buildSSFilterArray($ss_filters);
        if (!empty($serverCriteria)) {
            $criteria = (!empty($criteria)) ? array($criteria, $serverCriteria) : $serverCriteria;
        }

        return $criteria;
    }

    protected static function buildSSFilterArray($ss_filters)
    {
        if (empty($ss_filters)) {
            return null;
        }

        // build the server side criteria
        $filters = ArrayUtils::get($ss_filters, 'filters');
        if (empty($filters)) {
            return null;
        }

        $criteria = array();
        $combiner = ArrayUtils::get($ss_filters, 'filter_op', 'and');
        foreach ($filters as $filter) {
            $name = ArrayUtils::get($filter, 'name');
            $op = ArrayUtils::get($filter, 'operator');
            if (empty($name) || empty($op)) {
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
            }

            $value = ArrayUtils::get($filter, 'value');
            $value = static::interpretFilterValue($value);

            $criteria[] = static::buildFilterArray("$name $op $value");
        }

        if (1 == count($criteria)) {
            return $criteria[0];
        }

        switch (strtoupper($combiner)) {
            case 'AND':
                break;
            case 'OR':
                $criteria = array('split' => $criteria);
                break;
            default:
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
        }

        return $criteria;
    }

    /**
     * @param string|array $filter Filter for querying records by
     * @param null|array   $params
     *
     * @throws BadRequestException
     * @return array
     */
    protected static function buildFilterArray($filter, $params = null)
    {
        if (empty($filter)) {
            return array();
        }

        if (is_array($filter)) {
            return $filter; // assume they know what they are doing
        }

        $search = array(' or ', ' and ', ' nor ');
        $replace = array(' || ', ' && ', ' NOR ');
        $filter = trim(str_ireplace($search, $replace, $filter));

        // handle logical operators first
        $ops = array_map('trim', explode(' && ', $filter));
        if (count($ops) > 1) {
            $parts = array();
            foreach ($ops as $op) {
                $parts = array_merge($parts, static::buildFilterArray($op, $params));
            }

            return $parts;
        }

        $ops = array_map('trim', explode(' || ', $filter));
        if (count($ops) > 1) {
            // need to split this into multiple queries
            throw new BadRequestException('OR logical comparison not currently supported on DynamoDb.');
        }

        $ops = array_map('trim', explode(' NOR ', $filter));
        if (count($ops) > 1) {
            throw new BadRequestException('NOR logical comparison not currently supported on DynamoDb.');
        }

        // handle negation operator, i.e. starts with NOT?
        if (0 == substr_compare($filter, 'not ', 0, 4, true)) {
            throw new BadRequestException('NOT logical comparison not currently supported on DynamoDb.');
        }

        // the rest should be comparison operators
        $search = array(
            ' eq ',
            ' ne ',
            ' <> ',
            ' gte ',
            ' lte ',
            ' gt ',
            ' lt ',
            ' in ',
            ' between ',
            ' begins_with ',
            ' contains ',
            ' not_contains ',
            ' like '
        );
        $replace = array(
            '=',
            '!=',
            '!=',
            '>=',
            '<=',
            '>',
            '<',
            ' IN ',
            ' BETWEEN ',
            ' BEGINS_WITH ',
            ' CONTAINS ',
            ' NOT_CONTAINS ',
            ' LIKE '
        );
        $filter = trim(str_ireplace($search, $replace, $filter));

        // Note: order matters, watch '='
        $sqlOperators = array(
            '!=',
            '>=',
            '<=',
            '=',
            '>',
            '<',
            ' IN ',
            ' BETWEEN ',
            ' BEGINS_WITH ',
            ' CONTAINS ',
            ' NOT_CONTAINS ',
            ' LIKE '
        );
        $dynamoOperators = array(
            ComparisonOperator::NE,
            ComparisonOperator::GE,
            ComparisonOperator::LE,
            ComparisonOperator::EQ,
            ComparisonOperator::GT,
            ComparisonOperator::LT,
            ComparisonOperator::IN,
            ComparisonOperator::BETWEEN,
            ComparisonOperator::BEGINS_WITH,
            ComparisonOperator::CONTAINS,
            ComparisonOperator::NOT_CONTAINS,
            ComparisonOperator::CONTAINS
        );

        foreach ($sqlOperators as $key => $sqlOp) {
            $ops = array_map('trim', explode($sqlOp, $filter));
            if (count($ops) > 1) {
//                $field = $ops[0];
                $val = static::determineValue($ops[1], $params);
                $dynamoOp = $dynamoOperators[$key];
                switch ($dynamoOp) {
                    case ComparisonOperator::NE:
                        if (0 == strcasecmp('null', $ops[1])) {
                            return array(
                                $ops[0] => array(
                                    'ComparisonOperator' => ComparisonOperator::NOT_NULL
                                )
                            );
                        }

                        return array(
                            $ops[0] => array(
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => $dynamoOp
                            )
                        );

                    case ComparisonOperator::EQ:
                        if (0 == strcasecmp('null', $ops[1])) {
                            return array(
                                $ops[0] => array(
                                    'ComparisonOperator' => ComparisonOperator::NULL
                                )
                            );
                        }

                        return array(
                            $ops[0] => array(
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => ComparisonOperator::EQ
                            )
                        );

                    case ComparisonOperator::CONTAINS:
//			WHERE name LIKE "%Joe%"	use CONTAINS "Joe"
//			WHERE name LIKE "Joe%"	use BEGINS_WITH "Joe"
//			WHERE name LIKE "%Joe"	not supported
                        $val = $ops[1];
                        $type = Type::S;
                        if (trim($val, "'\"") === $val) {
                            $type = Type::N;
                        }

                        $val = trim($val, "'\"");
                        if ('%' == $val[strlen($val) - 1]) {
                            if ('%' == $val[0]) {
                                return array(
                                    $ops[0] => array(
                                        'AttributeValueList' => array($type => trim($val, '%')),
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    )
                                );
                            } else {
                                throw new BadRequestException('ENDS_WITH currently not supported in DynamoDb.');
                            }
                        } else {
                            if ('%' == $val[0]) {
                                return array(
                                    $ops[0] => array(
                                        'AttributeValueList' => array($type => trim($val, '%')),
                                        'ComparisonOperator' => ComparisonOperator::BEGINS_WITH
                                    )
                                );
                            } else {
                                return array(
                                    $ops[0] => array(
                                        'AttributeValueList' => array($type => trim($val, '%')),
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    )
                                );
                            }
                        }

                    default:
                        return array(
                            $ops[0] => array(
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => $dynamoOp
                            )
                        );
                }
            }
        }

        return $filter;
    }

    /**
     * @param string $value
     * @param array  $replacements
     *
     * @return bool|float|int|string
     */
    private static function determineValue($value, $replacements = null)
    {
        // process parameter replacements
        if (is_string($value) && !empty($value) && (':' == $value[0])) {
            if (isset($replacements, $replacements[$value])) {
                $value = $replacements[$value];
            }
        }

        if (trim($value, "'\"") !== $value) {
            return array(array(Type::S => trim($value, "'\""))); // meant to be a string
        }

        if (is_numeric($value)) {
            $value = ($value == strval(intval($value))) ? intval($value) : floatval($value);

            return array(array(Type::N => $value));
        }

        if (0 == strcasecmp($value, 'true')) {
            return array(array(Type::N => 1));
        }

        if (0 == strcasecmp($value, 'false')) {
            return array(array(Type::N => 0));
        }

        return $value;
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
                $this->parent->getConnection()->putItem(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Item'                  => $native,
                        'Expected'              => array($idFields[0] => array('Exists' => false))
                    )
                );

                if ($rollback) {
                    $key = static::buildKey($idsInfo, $record);
                    $this->addToRollback($key);
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

                $native = $this->formatAttributes($parsed);

                if (!$continue && !$rollback) {
                    return parent::addToTransaction($native, $id);
                }

                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::NONE;
                $result = $this->parent->getConnection()->putItem(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Item'                  => $native,
                        //                            'Expected'     => $expected,
                        'ReturnValues'          => $options
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

                $key = static::buildKey($idsInfo, $parsed, true);
                $native = $this->formatAttributes($parsed, true);

                // simple insert request
                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::ALL_NEW;

                $result = $this->parent->getConnection()->updateItem(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Key'                   => $key,
                        'AttributeUpdates'      => $native,
                        'ReturnValues'          => $options
                    )
                );

                $temp = ArrayUtils::get($result, 'Attributes', array());
                if ($rollback) {
                    $this->addToRollback($temp);

                    // merge old record with new changes
                    $new = array_merge($this->unformatAttributes($temp), $updates);
                    $out = static::cleanRecord($new, $fields, $idFields);
                } else {
                    $temp = $this->unformatAttributes($temp);
                    $out = static::cleanRecord($temp, $fields, $idFields);
                }
                break;

            case Verbs::DELETE:
                if (!$continue && !$rollback) {
                    return parent::addToTransaction(null, $id);
                }

                $record = array($idFields[0] => $id);
                $key = static::buildKey($idsInfo, $record);

                $result = $this->parent->getConnection()->deleteItem(
                    array(
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Key'                   => $key,
                        'ReturnValues'          => ReturnValue::ALL_OLD,
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
                $record = array($idFields[0] => $id);
                $key = static::buildKey($idsInfo, $record);
                $scanProperties = array(
                    static::TABLE_INDICATOR => $this->transactionTable,
                    'Key'                   => $key,
                    'ConsistentRead'        => true,
                );

                $fields = static::buildAttributesToGet($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['AttributesToGet'] = $fields;
                }

                $result = $this->parent->getConnection()->getItem($scanProperties);
                $result = $result['Item'];
                if (empty($result)) {
                    throw new NotFoundException('Record not found.');
                }

                // Grab value from the result object like an array
                $out = $this->unformatAttributes($result['Item']);
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

//        $ssFilters = ArrayUtils::get( $extras, 'ss_filters' );
        $fields = ArrayUtils::get($extras, 'fields');
        $requireMore = ArrayUtils::get($extras, 'require_more');
        $idsInfo = ArrayUtils::get($extras, 'ids_info');
        $idFields = ArrayUtils::get($extras, 'id_fields');

        $out = array();
        switch ($this->getAction()) {
            case Verbs::POST:
                $requests = array();
                foreach ($this->batchRecords as $item) {
                    $requests[] = array('PutRequest' => array('Item' => $item));
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->transactionTable => $requests))
                );

                // todo check $result['UnprocessedItems'] for 'PutRequest'

                foreach ($this->batchRecords as $item) {
                    $out[] = static::cleanRecord($this->unformatAttributes($item), $fields, $idFields);
                }
                break;

            case Verbs::PUT:
                $requests = array();
                foreach ($this->batchRecords as $item) {
                    $requests[] = array('PutRequest' => array('Item' => $item));
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->transactionTable => $requests))
                );

                // todo check $result['UnprocessedItems'] for 'PutRequest'

                foreach ($this->batchRecords as $item) {
                    $out[] = static::cleanRecord($this->unformatAttributes($item), $fields, $idFields);
                }
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                throw new BadRequestException('Batch operation not supported for patch.');
                break;

            case Verbs::DELETE:
                $requests = array();
                foreach ($this->batchIds as $id) {
                    $record = array($idFields[0] => $id);
                    $out[] = $record;
                    $key = static::buildKey($idsInfo, $record);
                    $requests[] = array('DeleteRequest' => array('Key' => $key));
                }
                if ($requireMore) {
                    $scanProperties = array(
                        'Keys'           => $this->batchRecords,
                        'ConsistentRead' => true,
                    );

                    $attributes = static::buildAttributesToGet($fields, $idFields);
                    if (!empty($attributes)) {
                        $scanProperties['AttributesToGet'] = $attributes;
                    }

                    // Get multiple items by key in a BatchGetItem request
                    $result = $this->parent->getConnection()->batchGetItem(
                        array(
                            'RequestItems' => array(
                                $this->transactionTable => $scanProperties
                            )
                        )
                    );

                    $out = array();
                    $items = $result->getPath("Responses/{$this->transactionTable}");
                    foreach ($items as $item) {
                        $out[] = $this->unformatAttributes($item);
                    }
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->transactionTable => $requests))
                );

                // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                break;

            case Verbs::GET:
                $keys = array();
                foreach ($this->batchIds as $id) {
                    $record = array($idFields[0] => $id);
                    $key = static::buildKey($idsInfo, $record);
                    $keys[] = $key;
                }

                $scanProperties = array(
                    'Keys'           => $keys,
                    'ConsistentRead' => true,
                );

                $fields = static::buildAttributesToGet($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['AttributesToGet'] = $fields;
                }

                // Get multiple items by key in a BatchGetItem request
                $result = $this->parent->getConnection()->batchGetItem(
                    array(
                        'RequestItems' => array(
                            $this->transactionTable => $scanProperties
                        )
                    )
                );

                $items = $result->getPath("Responses/{$this->transactionTable}");
                foreach ($items as $item) {
                    $out[] = $this->unformatAttributes($item);
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
                    $requests = array();
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = array('DeleteRequest' => array('Key' => $item));
                    }

                    /* $result = */
                    $this->parent->getConnection()->batchWriteItem(
                        array('RequestItems' => array($this->transactionTable => $requests))
                    );

                    // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $requests = array();
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = array('PutRequest' => array('Item' => $item));
                    }

                    /* $result = */
                    $this->parent->getConnection()->batchWriteItem(
                        array('RequestItems' => array($this->transactionTable => $requests))
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