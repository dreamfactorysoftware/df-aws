<?php
namespace DreamFactory\Core\Aws\Resources;

use Aws\DynamoDb\Marshaler;
use DreamFactory\Core\Aws\Enums\ComparisonOperator;
use DreamFactory\Core\Aws\Enums\ReturnValue;
use DreamFactory\Core\Aws\Enums\Type;
use DreamFactory\Core\Database\ColumnSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Resources\BaseDbTableResource;
use DreamFactory\Core\Aws\Services\DynamoDb;

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
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter($table, $filter = null, $params = [], $extras = [])
    {
        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        $ssFilters = ArrayUtils::get($extras, 'ss_filters');

        $scanProperties = [static::TABLE_INDICATOR => $table];

        $fields = static::buildAttributesToGet($fields);
        if (!empty($fields)) {
            $scanProperties['AttributesToGet'] = $fields;
        }

        $parsedFilter = static::buildCriteriaArray($filter, $params, $ssFilters);
        if (!empty($parsedFilter)) {
            $scanProperties['ScanFilter'] = $parsedFilter;
        }

        $limit = intval(ArrayUtils::get($extras, ApiOptions::LIMIT));
        if ($limit > 0) {
            $scanProperties['Limit'] = $limit;
            $scanProperties['Count'] = true;
        }
        $offset = intval(ArrayUtils::get($extras, ApiOptions::OFFSET));
        if ($offset > 0) {
            $scanProperties['ExclusiveStartKey'] = $offset;
            $scanProperties['Count'] = true;
        }

        try {
            $result = $this->parent->getConnection()->scan($scanProperties);
            $items = ArrayUtils::clean($result['Items']);

            $out = [];
            foreach ($items as $item) {
                $out[] = $this->unformatAttributes($item);
            }

            $next = $this->unformatAttributes($result['LastEvaluatedKey']);
            $count = $result['Count'];

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter records from '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * @param array $record
     * @param bool  $for_update
     *
     * @return mixed
     */
    protected function formatAttributes($record, $for_update = false)
    {
        $marshaler = new Marshaler();

        if ($for_update) {
            if (is_array($record)) {
                foreach ($record as $key => &$value) {
                    $value = ['Action' => 'PUT', 'Value' => $marshaler->marshalValue($value)];
                }

                return $record;
            }
        }

        return $marshaler->marshalItem($record);
    }

    /**
     * @param array $native
     *
     * @return array
     */
    protected function unformatAttributes($native)
    {
        $out = [];
        if (is_array($native)) {
            foreach ($native as $key => $value) {
                $out[$key] = static::unformatValue($value);
            }
        }

        return $out;
    }

    protected static function unformatValue($value)
    {
        $marshaler = new Marshaler();

        return $marshaler->unmarshalValue($value);

        // represented as arrays, though there is only ever one item present
//        foreach ($value as $type => $actual) {
//            switch ($type) {
//                case Type::S:
//                case Type::B:
//                    return $actual;
//                case Type::N:
//                    if (intval($actual) == $actual) {
//                        return intval($actual);
//                    } else {
//                        return floatval($actual);
//                    }
//                case Type::SS:
//                case Type::BS:
//                    return $actual;
//                case Type::NS:
//                    $out = [];
//                    foreach ($actual as $item) {
//                        if (intval($item) == $item) {
//                            $out[] = intval($item);
//                        } else {
//                            $out[] = floatval($item);
//                        }
//                    }
//
//                    return $out;
//            }
//        }
//
//        return $value;
    }

    protected static function buildAttributesToGet($fields = null, $id_fields = null)
    {
        if (ApiOptions::FIELDS_ALL == $fields) {
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
        $requested_fields = [];
        $result = $this->parent->getConnection()->describeTable([static::TABLE_INDICATOR => $table]);
        $result = $result['Table'];
        $keys = ArrayUtils::get($result, 'KeySchema', []);
        $definitions = ArrayUtils::get($result, 'AttributeDefinitions', []);
        $fields = [];
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
            $fields[] =
                new ColumnSchema(['name' => $name, 'key_type' => $keyType, 'type' => $type, 'required' => true]);
        }

        return $fields;
    }

    protected static function checkForIds(&$record, $ids_info, $extras = null, $on_create = false, $remove = false)
    {
        $id = null;
        if (!empty($ids_info)) {
            if (1 == count($ids_info)) {
                $info = $ids_info[0];
                $name = ArrayUtils::get($info, 'name');
                if (is_array($record)) {
                    $value = ArrayUtils::get($record, $name);
                    if ($remove) {
                        unset($record[$name]);
                    }
                } else {
                    $value = $record;
                }
                if (!empty($value)) {
                    $type = ArrayUtils::get($info, 'type');
                    switch ($type) {
                        case Type::N:
                            $value = intval($value);
                            break;
                        case Type::S:
                            $value = strval($value);
                            break;
                    }
                    $id = $value;
                } else {
                    $required = ArrayUtils::getBool($info, 'required');
                    // could be passed in as a parameter affecting all records
                    $param = ArrayUtils::get($extras, $name);
                    if ($on_create && $required && empty($param)) {
                        return false;
                    }
                }
            } else {
                $id = [];
                foreach ($ids_info as $info) {
                    $name = ArrayUtils::get($info, 'name');
                    if (is_array($record)) {
                        $value = ArrayUtils::get($record, $name);
                        if ($remove) {
                            unset($record[$name]);
                        }
                    } else {
                        $value = $record;
                    }
                    if (!empty($value)) {
                        $type = ArrayUtils::get($info, 'type');
                        switch ($type) {
                            case Type::N:
                                $value = intval($value);
                                break;
                            case Type::S:
                                $value = strval($value);
                                break;
                        }
                        $id[$name] = $value;
                    } else {
                        $required = ArrayUtils::getBool($info, 'required');
                        // could be passed in as a parameter affecting all records
                        $param = ArrayUtils::get($extras, $name);
                        if ($on_create && $required && empty($param)) {
                            return false;
                        }
                    }
                }
            }
        }

        if (!empty($id)) {
            return $id;
        } elseif ($on_create) {
            return [];
        }

        return false;
    }

    protected static function buildKey($ids_info, &$record, $remove = false)
    {
        $keys = [];
        foreach ($ids_info as $info) {
            $name = ArrayUtils::get($info, 'name');
            $type = ArrayUtils::get($info, 'type');
            $value = ArrayUtils::get($record, $name, null);
            if ($remove) {
                unset($record[$name]);
            }
            if (empty($value)) {
                throw new BadRequestException("Identifying field(s) not found in record.");
            }

            switch ($type) {
                case Type::N:
                    $value = [Type::N => strval($value)];
                    break;
                default:
                    $value = [Type::S => $value];
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
            Session::replaceLookups($filter);
            $criteria = static::buildFilterArray($filter, $params);
        } else {
            $criteria = $filter;
        }
        $serverCriteria = static::buildSSFilterArray($ss_filters);
        if (!empty($serverCriteria)) {
            $criteria = (!empty($criteria)) ? [$criteria, $serverCriteria] : $serverCriteria;
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

        $criteria = [];
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
                $criteria = ['split' => $criteria];
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
            return [];
        }

        if (is_array($filter)) {
            return $filter; // assume they know what they are doing
        }

        $search = [' or ', ' and ', ' nor '];
        $replace = [' || ', ' && ', ' NOR '];
        $filter = trim(str_ireplace($search, $replace, $filter));

        // handle logical operators first
        $ops = array_map('trim', explode(' && ', $filter));
        if (count($ops) > 1) {
            $parts = [];
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
        $search = [
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
        ];
        $replace = [
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
        ];
        $filter = trim(str_ireplace($search, $replace, $filter));

        // Note: order matters, watch '='
        $sqlOperators = [
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
        ];
        $dynamoOperators = [
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
        ];

        foreach ($sqlOperators as $key => $sqlOp) {
            $ops = array_map('trim', explode($sqlOp, $filter));
            if (count($ops) > 1) {
//                $field = $ops[0];
                $val = static::determineValue($ops[1], $params);
                $dynamoOp = $dynamoOperators[$key];
                switch ($dynamoOp) {
                    case ComparisonOperator::NE:
                        if (0 == strcasecmp('null', $ops[1])) {
                            return [
                                $ops[0] => [
                                    'ComparisonOperator' => ComparisonOperator::NOT_NULL
                                ]
                            ];
                        }

                        return [
                            $ops[0] => [
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => $dynamoOp
                            ]
                        ];

                    case ComparisonOperator::EQ:
                        if (0 == strcasecmp('null', $ops[1])) {
                            return [
                                $ops[0] => [
                                    'ComparisonOperator' => ComparisonOperator::NULL
                                ]
                            ];
                        }

                        return [
                            $ops[0] => [
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => ComparisonOperator::EQ
                            ]
                        ];

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
                                return [
                                    $ops[0] => [
                                        'AttributeValueList' => [$type => trim($val, '%')],
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    ]
                                ];
                            } else {
                                throw new BadRequestException('ENDS_WITH currently not supported in DynamoDb.');
                            }
                        } else {
                            if ('%' == $val[0]) {
                                return [
                                    $ops[0] => [
                                        'AttributeValueList' => [$type => trim($val, '%')],
                                        'ComparisonOperator' => ComparisonOperator::BEGINS_WITH
                                    ]
                                ];
                            } else {
                                return [
                                    $ops[0] => [
                                        'AttributeValueList' => [$type => trim($val, '%')],
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    ]
                                ];
                            }
                        }

                    default:
                        return [
                            $ops[0] => [
                                'AttributeValueList' => $val,
                                'ComparisonOperator' => $dynamoOp
                            ]
                        ];
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
            return [[Type::S => trim($value, "'\"")]]; // meant to be a string
        }

        if (is_numeric($value)) {
            $value = ($value == strval(intval($value))) ? intval($value) : floatval($value);

            // Scan strangely requires numbers to be strings.
            return [[Type::N => strval($value)]];
        }

        if (0 == strcasecmp($value, 'true')) {
            return [[Type::N => 1]];
        }

        if (0 == strcasecmp($value, 'false')) {
            return [[Type::N => 0]];
        }

        return $value;
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
        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        $idFields = ArrayUtils::get($extras, 'id_fields');
        $updates = ArrayUtils::get($extras, 'updates');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $native = $this->formatAttributes($parsed);

                $result = $this->parent->getConnection()->putItem(
                    [
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Item'                  => $native,
                        'Expected'              => [$idFields[0] => ['Exists' => false]]
                    ]
                );

                if ($rollback) {
                    $key = static::buildKey($this->tableIdsInfo, $record);
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

                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters, true);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $native = $this->formatAttributes($parsed);

                if (!$continue && !$rollback) {
                    return parent::addToTransaction($native, $id);
                }

                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::NONE;
                $result = $this->parent->getConnection()->putItem(
                    [
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Item'                  => $native,
                        //                            'Expected'     => $expected,
                        'ReturnValues'          => $options
                    ]
                );

                if ($rollback) {
                    $temp = $result['Attributes'];
                    if (!empty($temp)) {
                        $this->addToRollback($temp);
                    }
                }

                $out = static::cleanRecord($record, $fields, $idFields);
                break;

            case Verbs::PATCH:
                if (!empty($updates)) {
                    $updates[$idFields[0]] = $id;
                    $record = $updates;
                }

                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters, true);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $key = static::buildKey($this->tableIdsInfo, $parsed, true);
                $native = $this->formatAttributes($parsed, true);

                // simple insert request
                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::ALL_NEW;

                $result = $this->parent->getConnection()->updateItem(
                    [
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Key'                   => $key,
                        'AttributeUpdates'      => $native,
                        'ReturnValues'          => $options
                    ]
                );

                $temp = $result['Attributes'];
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

                $record = [$idFields[0] => $id];
                $key = static::buildKey($this->tableIdsInfo, $record);

                $result = $this->parent->getConnection()->deleteItem(
                    [
                        static::TABLE_INDICATOR => $this->transactionTable,
                        'Key'                   => $key,
                        'ReturnValues'          => ReturnValue::ALL_OLD,
                    ]
                );

                $temp = $result['Attributes'];

                if ($rollback) {
                    $this->addToRollback($temp);
                }

                $temp = $this->unformatAttributes($temp);
                $out = static::cleanRecord($temp, $fields, $idFields);
                break;

            case Verbs::GET:
                $record = [$idFields[0] => $id];
                $key = static::buildKey($this->tableIdsInfo, $record);
                $scanProperties = [
                    static::TABLE_INDICATOR => $this->transactionTable,
                    'Key'                   => $key,
                    'ConsistentRead'        => true,
                ];

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
                $out = $this->unformatAttributes($result);
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
        $fields = ArrayUtils::get($extras, ApiOptions::FIELDS);
        $requireMore = ArrayUtils::get($extras, 'require_more');
        $idFields = ArrayUtils::get($extras, 'id_fields');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $requests = [];
                foreach ($this->batchRecords as $item) {
                    $requests[] = ['PutRequest' => ['Item' => $item]];
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    ['RequestItems' => [$this->transactionTable => $requests]]
                );

                // todo check $result['UnprocessedItems'] for 'PutRequest'

                foreach ($this->batchRecords as $item) {
                    $out[] = static::cleanRecord($this->unformatAttributes($item), $fields, $idFields);
                }
                break;

            case Verbs::PUT:
                $requests = [];
                foreach ($this->batchRecords as $item) {
                    $requests[] = ['PutRequest' => ['Item' => $item]];
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    ['RequestItems' => [$this->transactionTable => $requests]]
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
                $requests = [];
                foreach ($this->batchIds as $id) {
                    $record = [$idFields[0] => $id];
                    $out[] = $record;
                    $key = static::buildKey($this->tableIdsInfo, $record);
                    $requests[] = ['DeleteRequest' => ['Key' => $key]];
                }
                if ($requireMore) {
                    $scanProperties = [
                        'Keys'           => $this->batchRecords,
                        'ConsistentRead' => true,
                    ];

                    $attributes = static::buildAttributesToGet($fields, $idFields);
                    if (!empty($attributes)) {
                        $scanProperties['AttributesToGet'] = $attributes;
                    }

                    // Get multiple items by key in a BatchGetItem request
                    $result = $this->parent->getConnection()->batchGetItem(
                        [
                            'RequestItems' => [
                                $this->transactionTable => $scanProperties
                            ]
                        ]
                    );

                    $out = [];
                    $items = $result->getPath("Responses/{$this->transactionTable}");
                    foreach ($items as $item) {
                        $out[] = $this->unformatAttributes($item);
                    }
                }

                /*$result = */
                $this->parent->getConnection()->batchWriteItem(
                    ['RequestItems' => [$this->transactionTable => $requests]]
                );

                // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                break;

            case Verbs::GET:
                $keys = [];
                foreach ($this->batchIds as $id) {
                    $record = [$idFields[0] => $id];
                    $key = static::buildKey($this->tableIdsInfo, $record);
                    $keys[] = $key;
                }

                $scanProperties = [
                    'Keys'           => $keys,
                    'ConsistentRead' => true,
                ];

                $fields = static::buildAttributesToGet($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['AttributesToGet'] = $fields;
                }

                // Get multiple items by key in a BatchGetItem request
                $result = $this->parent->getConnection()->batchGetItem(
                    [
                        'RequestItems' => [
                            $this->transactionTable => $scanProperties
                        ]
                    ]
                );

                $items = $result->getPath("Responses/{$this->transactionTable}");
                foreach ($items as $item) {
                    $out[] = $this->unformatAttributes($item);
                }
                break;
            default:
                break;
        }

        $this->batchIds = [];
        $this->batchRecords = [];

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
                    $requests = [];
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = ['DeleteRequest' => ['Key' => $item]];
                    }

                    /* $result = */
                    $this->parent->getConnection()->batchWriteItem(
                        ['RequestItems' => [$this->transactionTable => $requests]]
                    );

                    // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $requests = [];
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = ['PutRequest' => ['Item' => $item]];
                    }

                    /* $result = */
                    $this->parent->getConnection()->batchWriteItem(
                        ['RequestItems' => [$this->transactionTable => $requests]]
                    );

                    // todo check $result['UnprocessedItems'] for 'PutRequest'
                    break;

                default:
                    break;
            }

            $this->rollbackRecords = [];
        }

        return true;
    }
}