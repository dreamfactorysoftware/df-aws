<?php
namespace DreamFactory\Core\Aws\Resources;

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Marshaler;
use DreamFactory\Core\Aws\Enums\ReturnValue;
use DreamFactory\Core\Aws\Enums\Type;
use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Enums\ApiOptions;
use DreamFactory\Core\Database\Resources\BaseNoSqlDbTableResource;
use DreamFactory\Core\Enums\DbComparisonOperators;
use DreamFactory\Core\Enums\DbLogicalOperators;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Scalar;

class DynamoDbTable extends BaseNoSqlDbTableResource
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
     * @return DynamoDbClient
     */
    protected function getConnection()
    {
        return $this->parent->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter($table, $filter = null, $params = [], $extras = [])
    {
        $fields = array_get($extras, ApiOptions::FIELDS);
        $ssFilters = array_get($extras, 'ss_filters');

        $scanProperties = [static::TABLE_INDICATOR => $table];

        $fields = static::buildProjectionExpression($fields);
        if (!empty($fields)) {
            $scanProperties['ProjectionExpression'] = $fields;
        }

        $this->buildFilterExpression($filter, $params, $ssFilters, $scanProperties);

        $limit = intval(array_get($extras, ApiOptions::LIMIT));
        if ($limit > 0) {
            $scanProperties['Limit'] = $limit;
            $scanProperties['Count'] = true;
        }
        $offset = intval(array_get($extras, ApiOptions::OFFSET));
        if ($offset > 0) {
            $scanProperties['ExclusiveStartKey'] = $offset;
            $scanProperties['Count'] = true;
        }

        try {
            $result = $this->getConnection()->scan($scanProperties);
            $items = (array)$result['Items'];

            $out = [];
            foreach ($items as $item) {
                $out[] = $this->unformatAttributes($item);
            }

            $next = $this->unformatAttributes($result['LastEvaluatedKey']);
            $next = current($next); // todo handle more than one index here.
            $count = $result['ScannedCount'];
            $out = static::cleanRecords($out);
            $needMore = ($limit > 0) ? (($count - $offset) > $limit) : false;
            $addCount = Scalar::boolval(array_get($extras, ApiOptions::INCLUDE_COUNT));
            if ($addCount || $needMore || $next) {
                $out['meta']['count'] = $count;
                if ($next) {
                    $out['meta']['next'] = $next;
                }
            }

            return $out;
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("Failed to filter records from '$table'.\n{$ex->getMessage()}");
        }
    }

    /**
     * @param array $record
     *
     * @return mixed
     */
    protected function formatAttributes($record)
    {
        $marshaler = new Marshaler();

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
    }

    protected static function formatValue($value)
    {
        $marshaler = new Marshaler();

        return $marshaler->marshalValue($value);
    }

    protected static function buildProjectionExpression($fields = null, $id_fields = null)
    {
        if (ApiOptions::FIELDS_ALL == $fields) {
            return null;
        }
        if (empty($fields)) {
            if (empty($id_fields)) {
                return null;
            }
            if (is_array($id_fields)) {
                $id_fields = implode(',', $id_fields);
            }

            return $id_fields;
        }

        if (is_array($fields)) {
            $fields = implode(',', $fields);
        }

        return $fields;
    }

    protected function getIdsInfo($table, $fields_info = null, &$requested_fields = null, $requested_types = null)
    {
        $requested_fields = [];
        $result = $this->getConnection()->describeTable([static::TABLE_INDICATOR => $table]);
        $result = $result['Table'];
        $keys = array_get($result, 'KeySchema', []);
        $definitions = array_get($result, 'AttributeDefinitions', []);
        $fields = [];
        foreach ($keys as $key) {
            $name = array_get($key, 'AttributeName');
            $keyType = array_get($key, 'KeyType');
            $type = null;
            foreach ($definitions as $type) {
                if (0 == strcmp($name, array_get($type, 'AttributeName'))) {
                    $type = array_get($type, 'AttributeType');
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
                if (is_array($record)) {
                    $value = array_get($record, $info->name);
                    if ($remove) {
                        unset($record[$info->name]);
                    }
                } else {
                    $value = $record;
                }
                if (!empty($value)) {
                    switch ($info->type) {
                        case Type::N:
                            $value = intval($value);
                            break;
                        case Type::S:
                            $value = strval($value);
                            break;
                    }
                    $id = $value;
                } else {
                    // could be passed in as a parameter affecting all records
                    $param = array_get($extras, $info->name);
                    if ($on_create && $info->getRequired() && empty($param)) {
                        return false;
                    }
                }
            } else {
                $id = [];
                foreach ($ids_info as $info) {
                    if (is_array($record)) {
                        $value = array_get($record, $info->name);
                        if ($remove) {
                            unset($record[$info->name]);
                        }
                    } else {
                        $value = $record;
                    }
                    if (!empty($value)) {
                        switch ($info->type) {
                            case Type::N:
                                $value = intval($value);
                                break;
                            case Type::S:
                                $value = strval($value);
                                break;
                        }
                        $id[$info->name] = $value;
                    } else {
                        // could be passed in as a parameter affecting all records
                        $param = array_get($extras, $info->name);
                        if ($on_create && $info->getRequired() && empty($param)) {
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
            $value = array_get($record, $info->name, null);
            if ($remove) {
                unset($record[$info->name]);
            }
            if (empty($value)) {
                throw new BadRequestException("Identifying field(s) not found in record.");
            }

            switch ($info->type) {
                case Type::N:
                    $value = [Type::N => strval($value)];
                    break;
                default:
                    $value = [Type::S => $value];
            }
            $keys[$info->name] = $value;
        }

        return $keys;
    }

    protected function buildFilterExpression($filter, array $in_params = [], $ss_filters = null, array &$scan = [])
    {
        // interpret any parameter values as lookups
        $params = static::interpretRecordValues($in_params);
        $serverFilter = static::buildSSFilter($ss_filters);

        if (empty($filter)) {
            $filter = $serverFilter;
        } elseif (is_string($filter)) {
            if (!empty($serverFilter)) {
                $filter = '(' . $filter . ') ' . DbLogicalOperators::AND_STR . ' (' . $serverFilter . ')';
            }
        } elseif (is_array($filter)) {
            // todo parse client filter?
            $filter = '';
            if (!empty($serverFilter)) {
                $filter = '(' . $filter . ') ' . DbLogicalOperators::AND_STR . ' (' . $serverFilter . ')';
            }
        }

        Session::replaceLookups($filter);
        $outNames = [];
        $outParams = [];
        if (!empty($parsed = $this->parseFilterString($filter, $outNames, $outParams, $params))) {
            $scan['FilterExpression'] = $parsed;
            if (!empty($outNames)) {
                $scan['ExpressionAttributeNames'] = $outNames;
            }
            if (!empty($outParams)) {
                $scan['ExpressionAttributeValues'] = $outParams;
            }
        }
    }

    protected static function buildSSFilter($ss_filters)
    {
        if (empty($ss_filters)) {
            return '';
        }

        // build the server side criteria
        $filters = array_get($ss_filters, 'filters');
        if (empty($filters)) {
            return '';
        }

        $sql = '';
        $combiner = array_get($ss_filters, 'filter_op', DbLogicalOperators::AND_STR);
        switch (strtoupper($combiner)) {
            case 'AND':
            case 'OR':
                break;
            default:
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
        }
        foreach ($filters as $key => $filter) {
            if (!empty($sql)) {
                $sql .= " $combiner ";
            }

            $name = array_get($filter, 'name');
            $op = strtoupper(array_get($filter, 'operator'));
            if (empty($name) || empty($op)) {
                // log and bail
                throw new InternalServerErrorException('Invalid server-side filter configuration detected.');
            }

            if (DbComparisonOperators::requiresNoValue($op)) {
                $sql .= "($name $op)";
            } else {
                $value = array_get($filter, 'value');
                $sql .= "($name $op $value)";
            }
        }

        return $sql;
    }

    public static function localizeOperator($operator)
    {
        switch ($operator) {
            case DbComparisonOperators::NE_STR:
            case DbComparisonOperators::NE:
                return DbComparisonOperators::NE_2;
            // Value-Modifying Operators
            case DbComparisonOperators::CONTAINS:
            case DbComparisonOperators::STARTS_WITH:
            case DbComparisonOperators::ENDS_WITH:
                return DbComparisonOperators::LIKE;
            default:
                return parent::localizeOperator($operator);
        }
    }

    /**
     * @param string $filter
     * @param array  $out_names
     * @param array  $out_params
     * @param array  $in_params
     *
     * @return string
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \Exception
     */
    protected function parseFilterString($filter, array &$out_names, array &$out_params, array $in_params = [])
    {
        if (empty($filter)) {
            return null;
        }

        $filter = trim($filter);
        // todo use smarter regex
        // handle logical operators first
        $logicalOperators = DbLogicalOperators::getDefinedConstants();
        foreach ($logicalOperators as $logicalOp) {
            if (DbLogicalOperators::NOT_STR === $logicalOp) {
                // NOT(a = 1)  or NOT (a = 1)format
                if ((0 === stripos($filter, $logicalOp . ' (')) || (0 === stripos($filter, $logicalOp . '('))) {
                    $parts = trim(substr($filter, 3));
                    $parts = $this->parseFilterString($parts, $out_names, $out_params, $in_params);

                    return static::localizeOperator($logicalOp) . $parts;
                }
            } else {
                // (a = 1) AND (b = 2) format or (a = 1)AND(b = 2) format
                $filter = str_ireplace(')' . $logicalOp . '(', ') ' . $logicalOp . ' (', $filter);
                $paddedOp = ') ' . $logicalOp . ' (';
                if (false !== $pos = stripos($filter, $paddedOp)) {
                    $left = trim(substr($filter, 0, $pos)) . ')'; // add back right )
                    $right = '(' . trim(substr($filter, $pos + strlen($paddedOp))); // adding back left (
                    $left = $this->parseFilterString($left, $out_names, $out_params, $in_params);
                    $right = $this->parseFilterString($right, $out_names, $out_params, $in_params);

                    return $left . ' ' . static::localizeOperator($logicalOp) . ' ' . $right;
                }
            }
        }

        $wrap = false;
        if ((0 === strpos($filter, '(')) && ((strlen($filter) - 1) === strrpos($filter, ')'))) {
            // remove unnecessary wrapping ()
            $filter = substr($filter, 1, -1);
            $wrap = true;
        }

        // Some scenarios leave extra parens dangling
        $pure = trim($filter, '()');
        $pieces = explode($pure, $filter);
        $leftParen = (!empty($pieces[0]) ? $pieces[0] : null);
        $rightParen = (!empty($pieces[1]) ? $pieces[1] : null);
        $filter = $pure;

        // the rest should be comparison operators
        // Note: order matters here!
        $sqlOperators = DbComparisonOperators::getParsingOrder();
        foreach ($sqlOperators as $sqlOp) {
            $paddedOp = static::padOperator($sqlOp);
            if (false !== $pos = stripos($filter, $paddedOp)) {
                $field = trim(substr($filter, 0, $pos));
                $negate = false;
                if (false !== strpos($field, ' ')) {
                    $parts = explode(' ', $field);
                    $partsCount = count($parts);
                    if (($partsCount > 1) &&
                        (0 === strcasecmp($parts[$partsCount - 1], trim(DbLogicalOperators::NOT_STR)))
                    ) {
                        // negation on left side of operator
                        array_pop($parts);
                        $field = implode(' ', $parts);
                        $negate = true;
                    }
                }
                /** @type ColumnSchema $info */
                if (null === $info = new ColumnSchema(['name' => strtolower($field)])) {
                    // This could be SQL injection attempt or bad field
                    throw new BadRequestException("Invalid or unparsable field in filter request: '$field'");
                }

                // make sure we haven't chopped off right side too much
                $value = trim(substr($filter, $pos + strlen($paddedOp)));
                if ((0 !== strpos($value, "'")) &&
                    (0 !== $lpc = substr_count($value, '(')) &&
                    ($lpc !== $rpc = substr_count($value, ')'))
                ) {
                    // add back to value from right
                    $parenPad = str_repeat(')', $lpc - $rpc);
                    $value .= $parenPad;
                    $rightParen = preg_replace('/\)/', '', $rightParen, $lpc - $rpc);
                }
                if (DbComparisonOperators::requiresValueList($sqlOp)) {
                    if ((0 === strpos($value, '(')) && ((strlen($value) - 1) === strrpos($value, ')'))) {
                        // remove wrapping ()
                        $value = substr($value, 1, -1);
                        $parsed = [];
                        foreach (explode(',', $value) as $each) {
                            $parsed[] = $this->parseFilterValue(trim($each), $info, $out_params, $in_params);
                        }
                        $value = '(' . implode(',', $parsed) . ')';
                    } else {
                        throw new BadRequestException('Filter value lists must be wrapped in parentheses.');
                    }
                } elseif (DbComparisonOperators::requiresNoValue($sqlOp)) {
                    switch ($sqlOp) {
                        case DbComparisonOperators::IS_NULL:
                            $value = null;
                            $sqlOp = DbComparisonOperators::EQ;
                            break;
                        case DbComparisonOperators::IS_NOT_NULL:
                            $value = null;
                            $sqlOp = DbComparisonOperators::NE_2;
                            break;
                        case DbComparisonOperators::DOES_EXIST:
                            $out_names['#' . $field] = $field;

                            return "attribute_exists(#$field)";
                        case DbComparisonOperators::DOES_NOT_EXIST:
                            $out_names['#' . $field] = $field;

                            return "attribute_not_exists(#$field)";
                    }
                } else {
                    static::modifyValueByOperator($sqlOp, $value);
                    switch ($sqlOp) {
                        case DbComparisonOperators::LIKE:
                        case DbComparisonOperators::CONTAINS:
                        case DbComparisonOperators::STARTS_WITH:
                        case DbComparisonOperators::ENDS_WITH:
                            $out_names['#' . $field] = $field;
                            if ('%' == $value[strlen($value) - 1]) {
                                if ('%' == $value[0]) {
                                    $value = trim($value, '%');
                                    $value = $this->parseFilterValue($value, $info, $out_params, $in_params);

                                    return "contains(#$field, $value)";
                                } else {
                                    $value = trim($value, '%');
                                    $value = $this->parseFilterValue($value, $info, $out_params, $in_params);

                                    return "begins_with(#$field, $value)";
                                }
                            } elseif ('%' == $value[0]) {
                                throw new BadRequestException('ENDS WITH operator not supported on this service.');
                            }

                            $value = $this->parseFilterValue($value, $info, $out_params, $in_params);

                            return "contains(#$field, $value)";
                    }
                    $value = $this->parseFilterValue($value, $info, $out_params, $in_params);
                }

                $sqlOp = static::localizeOperator($sqlOp);
                if ($negate) {
                    $sqlOp = DbLogicalOperators::NOT_STR . ' ' . $sqlOp;
                }

                $out_names['#' . $field] = $field;
                $out = '#' . $field . ' ' . $sqlOp;
                $out .= (isset($value) ? " $value" : null);
                if ($leftParen) {
                    $out = $leftParen . $out;
                }
                if ($rightParen) {
                    $out .= $rightParen;
                }

                return ($wrap ? '(' . $out . ')' : $out);
            }
        }

        // This could be SQL injection attempt or unsupported filter arrangement
        throw new BadRequestException('Invalid or unparsable filter request.');
    }

    /**
     * @param mixed        $value
     * @param ColumnSchema $info
     * @param array        $out_params
     * @param array        $in_params
     *
     * @return int|null|string
     * @throws BadRequestException
     */
    protected function parseFilterValue($value, ColumnSchema $info, array &$out_params, array $in_params = [])
    {
        // if a named replacement parameter, un-name it because Laravel can't handle named parameters
        if (is_array($in_params) && (0 === strpos($value, ':'))) {
            if (array_key_exists($value, $in_params)) {
                $value = $in_params[$value];
            }
        }

        // remove quoting on strings if used, i.e. 1.x required them
        if (is_string($value)) {
            if ((0 === strcmp("'" . trim($value, "'") . "'", $value)) ||
                (0 === strcmp('"' . trim($value, '"') . '"', $value))
            ) {
                $value = substr($value, 1, -1);
            }
        }

        if (trim($value, "'\"") !== $value) {
            $value = trim($value, "'\""); // meant to be a string
        } elseif (is_numeric($value)) {
            $value = ($value == strval(intval($value))) ? intval($value) : floatval($value);
        } elseif (0 == strcasecmp($value, 'true')) {
            $value = true;
        } elseif (0 == strcasecmp($value, 'false')) {
            $value = false;
        }

        $out_params[':' . $info->name] = static::formatValue($value);
        $value = ':' . $info->name;

        return $value;
    }

    /**
     * @inheritdoc
     */
    protected function parseValueForSet($value, $field_info, $for_update = false)
    {
        if (DbSimpleTypes::TYPE_ID === $field_info->type) {
            // may be hash string or int, don't convert to int automatically
            return $value;
        } else {
            return parent::parseValueForSet($value, $field_info, $for_update);
        }
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
    ) {
        $ssFilters = array_get($extras, 'ss_filters');
        $fields = array_get($extras, ApiOptions::FIELDS);
        $idFields = array_get($extras, 'id_fields');
        $updates = array_get($extras, 'updates');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $parsed = $this->parseRecord($record, $this->tableFieldsInfo, $ssFilters);
                if (empty($parsed)) {
                    throw new BadRequestException('No valid fields were found in record.');
                }

                $item = [
                    static::TABLE_INDICATOR => $this->transactionTable,
                    'Item'                  => $this->formatAttributes($parsed),
                ];

                if (!Scalar::boolval(array_get($extras, ApiOptions::UPSERT, false))) {
                    $item['ConditionExpression'] = "attribute_not_exists($idFields[0])";
                }

                $this->getConnection()->putItem($item);

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

                $upsert = Scalar::boolval(array_get($extras, ApiOptions::UPSERT));
                if (!$continue && !$rollback && !$upsert) {
                    return parent::addToTransaction($native, $id);
                }

                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::NONE;
                $item = [
                    static::TABLE_INDICATOR => $this->transactionTable,
                    'Item'                  => $native,
                    'ReturnValues'          => $options,
                ];

                if (!$upsert) {
                    $item['ConditionExpression'] = "attribute_exists($idFields[0])";
                }

                $result = $this->getConnection()->putItem($item);

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
                $marshaler = new Marshaler();
                $expression = [];
                $outNames = [];
                $outParams = [];
                foreach ($parsed as $name => &$value) {
                    $outNames['#' . $name] = $name;
                    $outParams[':' . $name] = $marshaler->marshalValue($value);
                    $expression[] = "#$name = :$name";
                }

                $expression = (!empty($expression) ? 'SET ' . implode(', ', $expression) : null);

                // simple insert request
                $options = ($rollback) ? ReturnValue::ALL_OLD : ReturnValue::ALL_NEW;

                $result = $this->getConnection()->updateItem(
                    [
                        static::TABLE_INDICATOR     => $this->transactionTable,
                        'Key'                       => $key,
                        'UpdateExpression'          => $expression,
                        'ExpressionAttributeNames'  => $outNames,
                        'ExpressionAttributeValues' => $outParams,
                        'ReturnValues'              => $options
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

                $result = $this->getConnection()->deleteItem(
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

                $fields = static::buildProjectionExpression($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['ProjectionExpression'] = $fields;
                }

                $result = $this->getConnection()->getItem($scanProperties);
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

//        $ssFilters = array_get( $extras, 'ss_filters' );
        $fields = array_get($extras, ApiOptions::FIELDS);
        $requireMore = array_get($extras, 'require_more');
        $idFields = array_get($extras, 'id_fields');

        $out = [];
        switch ($this->getAction()) {
            case Verbs::POST:
                $requests = [];
                foreach ($this->batchRecords as $item) {
                    $requests[] = ['PutRequest' => ['Item' => $item]];
                }

                /*$result = */
                $this->getConnection()->batchWriteItem(
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
                $this->getConnection()->batchWriteItem(
                    ['RequestItems' => [$this->transactionTable => $requests]]
                );

                // todo check $result['UnprocessedItems'] for 'PutRequest'

                foreach ($this->batchRecords as $item) {
                    $out[] = static::cleanRecord($this->unformatAttributes($item), $fields, $idFields);
                }
                break;

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

                    $attributes = static::buildProjectionExpression($fields, $idFields);
                    if (!empty($attributes)) {
                        $scanProperties['ProjectionExpression'] = $attributes;
                    }

                    // Get multiple items by key in a BatchGetItem request
                    $result = $this->getConnection()->batchGetItem(
                        [
                            'RequestItems' => [
                                $this->transactionTable => $scanProperties
                            ]
                        ]
                    );

                    $out = [];
                    $items = $result->search(str_replace('/', '.', "Responses/{$this->transactionTable}"));
                    foreach ($items as $item) {
                        $out[] = $this->unformatAttributes($item);
                    }
                }

                /*$result = */
                $this->getConnection()->batchWriteItem(
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

                $fields = static::buildProjectionExpression($fields, $idFields);
                if (!empty($fields)) {
                    $scanProperties['ProjectionExpression'] = $fields;
                }

                // Get multiple items by key in a BatchGetItem request
                $result = $this->getConnection()->batchGetItem(
                    [
                        'RequestItems' => [
                            $this->transactionTable => $scanProperties
                        ]
                    ]
                );

                $items = $result->search(str_replace('/', '.', "Responses/{$this->transactionTable}"));
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
                    $this->getConnection()->batchWriteItem(
                        ['RequestItems' => [$this->transactionTable => $requests]]
                    );

                    // todo check $result['UnprocessedItems'] for 'DeleteRequest'
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::DELETE:
                    $requests = [];
                    foreach ($this->rollbackRecords as $item) {
                        $requests[] = ['PutRequest' => ['Item' => $item]];
                    }

                    /* $result = */
                    $this->getConnection()->batchWriteItem(
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