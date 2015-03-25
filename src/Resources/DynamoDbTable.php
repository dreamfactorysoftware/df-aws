<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Rave\Aws\Resources;

use Aws\DynamoDb\Enum\ComparisonOperator;
use Aws\DynamoDb\Enum\ReturnValue;
use Aws\DynamoDb\Enum\Type;
use Aws\DynamoDb\Model\Attribute;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
use DreamFactory\Rave\Resources\BaseDbTableResource;
use DreamFactory\Rave\Aws\Services\DynamoDb;
use DreamFactory\Rave\Utility\DbUtilities;

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
    protected $service = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * {@InheritDoc}
     */
    public function correctTableName( &$name )
    {
        static $_existing = null;

        if ( !$_existing )
        {
            $_existing = $this->service->getTables();
        }

        if ( empty( $name ) )
        {
            throw new BadRequestException( 'Table name can not be empty.' );
        }

        if ( false === array_search( $name, $_existing ) )
        {
            throw new NotFoundException( "Table '$name' not found." );
        }

        return $name;
    }

    /**
     * {@inheritdoc}
     */
    public function listResources($include_properties = null)
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getTables();

        if (empty($include_properties))
        {
            return array('resource' => $_names);
        }

        $_extras = DbUtilities::getSchemaExtrasForTables( $this->service->getServiceId(), $_names, false, 'table,label,plural' );

        $_tables = array();
        foreach ( $_names as $name )
        {
            $label = '';
            $plural = '';
            foreach ( $_extras as $each )
            {
                if ( 0 == strcasecmp( $name, ArrayUtils::get( $each, 'table', '' ) ) )
                {
                    $label = ArrayUtils::get( $each, 'label' );
                    $plural = ArrayUtils::get( $each, 'plural' );
                    break;
                }
            }

            if ( empty( $label ) )
            {
                $label = Inflector::camelize( $name, ['_','.'], true );
            }

            if ( empty( $plural ) )
            {
                $plural = Inflector::pluralize( $label );
            }

            $_tables[] = array('name' => $name, 'label' => $label, 'plural' => $plural);
        }

        return $this->makeResourceList($_tables, $include_properties, true);
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter( $table, $filter = null, $params = array(), $extras = array() )
    {
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );

        $_scanProperties = array(static::TABLE_INDICATOR => $table);

        $_fields = static::_buildAttributesToGet( $_fields );
        if ( !empty( $_fields ) )
        {
            $_scanProperties['AttributesToGet'] = $_fields;
        }

        $_parsedFilter = static::buildCriteriaArray( $filter, $params, $_ssFilters );
        if ( !empty( $_parsedFilter ) )
        {
            $_scanProperties['ScanFilter'] = $_parsedFilter;
        }

        $_limit = ArrayUtils::get( $extras, 'limit' );
        if ( $_limit > 0 )
        {
            $_scanProperties['Limit'] = $_limit;
        }

        try
        {
            $_result = $this->service->getConnection()->scan( $_scanProperties );
            $_items = ArrayUtils::clean( $_result['Items'] );

            $_out = array();
            foreach ( $_items as $_item )
            {
                $_out[] = $this->_unformatAttributes( $_item );
            }

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to filter records from '$table'.\n{$_ex->getMessage()}" );
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
    protected function parseRecord( $record, $fields_info, $filter_info = null, $for_update = false, $old_record = null )
    {
//        $record = DataFormat::arrayKeyLower( $record );
        $_parsed = ( empty( $fields_info ) ) ? $record : array();
        if ( !empty( $fields_info ) )
        {
            $_keys = array_keys( $record );
            $_values = array_values( $record );
            foreach ( $fields_info as $_fieldInfo )
            {
//            $name = strtolower( ArrayUtils::get( $field_info, 'name', '' ) );
                $_name = ArrayUtils::get( $_fieldInfo, 'name', '' );
                $_type = ArrayUtils::get( $_fieldInfo, 'type' );
                $_pos = array_search( $_name, $_keys );
                if ( false !== $_pos )
                {
                    $_fieldVal = ArrayUtils::get( $_values, $_pos );
                    // due to conversion from XML to array, null or empty xml elements have the array value of an empty array
                    if ( is_array( $_fieldVal ) && empty( $_fieldVal ) )
                    {
                        $_fieldVal = null;
                    }

                    /** validations **/

                    $_validations = ArrayUtils::get( $_fieldInfo, 'validation' );

                    if ( !static::validateFieldValue( $_name, $_fieldVal, $_validations, $for_update, $_fieldInfo ) )
                    {
                        unset( $_keys[$_pos] );
                        unset( $_values[$_pos] );
                        continue;
                    }

                    $_parsed[$_name] = $_fieldVal;
                    unset( $_keys[$_pos] );
                    unset( $_values[$_pos] );
                }

                // add or override for specific fields
                switch ( $_type )
                {
                    case 'timestamp_on_create':
                        if ( !$for_update )
                        {
                            $_parsed[$_name] = new \MongoDate();
                        }
                        break;
                    case 'timestamp_on_update':
                        $_parsed[$_name] = new \MongoDate();
                        break;
                    case 'user_id_on_create':
                        if ( !$for_update )
                        {
                            $userId = 1;//Session::getCurrentUserId();
                            if ( isset( $userId ) )
                            {
                                $_parsed[$_name] = $userId;
                            }
                        }
                        break;
                    case 'user_id_on_update':
                        $userId = 1;//Session::getCurrentUserId();
                        if ( isset( $userId ) )
                        {
                            $_parsed[$_name] = $userId;
                        }
                        break;
                }
            }
        }

        if ( !empty( $filter_info ) )
        {
            $this->validateRecord( $_parsed, $filter_info, $for_update, $old_record );
        }

        return $_parsed;
    }

    /**
     * @param array $record
     * @param bool  $for_update
     *
     * @return mixed
     */
    protected function _formatAttributes( $record, $for_update = false )
    {
        $_format = ( $for_update ) ? Attribute::FORMAT_UPDATE : Attribute::FORMAT_PUT;

        return $this->service->getConnection()->formatAttributes( $record, $_format );
    }

    /**
     * @param array $native
     *
     * @return array
     */
    protected function _unformatAttributes( $native )
    {
        $_out = array();
        if (is_array($native))
        {
            foreach ( $native as $_key => $_value )
            {
                $_out[$_key] = static::_unformatValue( $_value );
            }
        }

        return $_out;
    }

    protected static function _unformatValue( $value )
    {
        // represented as arrays, though there is only ever one item present
        foreach ( $value as $type => $actual )
        {
            switch ( $type )
            {
                case Type::S:
                case Type::B:
                    return $actual;
                case Type::N:
                    if ( intval( $actual ) == $actual )
                    {
                        return intval( $actual );
                    }
                    else
                    {
                        return floatval( $actual );
                    }
                case Type::SS:
                case Type::BS:
                    return $actual;
                case Type::NS:
                    $_out = array();
                    foreach ( $actual as $_item )
                    {
                        if ( intval( $_item ) == $_item )
                        {
                            $_out[] = intval( $_item );
                        }
                        else
                        {
                            $_out[] = floatval( $_item );
                        }
                    }

                    return $_out;
            }
        }

        return $value;
    }

    protected static function _buildAttributesToGet( $fields = null, $id_fields = null )
    {
        if ( '*' == $fields )
        {
            return null;
        }
        if ( empty( $fields ) )
        {
            if ( empty( $id_fields ) )
            {
                return null;
            }
            if ( !is_array( $id_fields ) )
            {
                $id_fields = array_map( 'trim', explode( ',', trim( $id_fields, ',' ) ) );
            }

            return $id_fields;
        }

        if ( !is_array( $fields ) )
        {
            $fields = array_map( 'trim', explode( ',', trim( $fields, ',' ) ) );
        }

        return $fields;
    }

    protected function getIdsInfo( $table, $fields_info = null, &$requested_fields = null, $requested_types = null )
    {
        $requested_fields = array();
        $_result = $this->service->getConnection()->describeTable( array(static::TABLE_INDICATOR => $table) );
        $_result = $_result['Table'];
        $_keys = ArrayUtils::get( $_result, 'KeySchema', array() );
        $_definitions = ArrayUtils::get( $_result, 'AttributeDefinitions', array() );
        $_fields = array();
        foreach ( $_keys as $_key )
        {
            $_name = ArrayUtils::get( $_key, 'AttributeName' );
            $_keyType = ArrayUtils::get( $_key, 'KeyType' );
            $_type = null;
            foreach ( $_definitions as $_type )
            {
                if ( 0 == strcmp( $_name, ArrayUtils::get( $_type, 'AttributeName' ) ) )
                {
                    $_type = ArrayUtils::get( $_type, 'AttributeType' );
                }
            }

            $requested_fields[] = $_name;
            $_fields[] = array('name' => $_name, 'key_type' => $_keyType, 'type' => $_type, 'required' => true);
        }

        return $_fields;
    }

    protected static function _buildKey( $ids_info, &$record, $remove = false )
    {
        $_keys = array();
        foreach ( $ids_info as $_info )
        {
            $_name = ArrayUtils::get( $_info, 'name' );
            $_type = ArrayUtils::get( $_info, 'type' );
            $_value = ArrayUtils::get( $record, $_name, null, $remove );
            if ( empty( $_value ) )
            {
                throw new BadRequestException( "Identifying field(s) not found in record." );
            }

            switch ( $_type )
            {
                case Type::N:
                    $_value = array(Type::N => strval( $_value ));
                    break;
                default:
                    $_value = array(Type::S => $_value);
            }
            $_keys[$_name] = $_value;
        }

        return $_keys;
    }

    protected static function buildCriteriaArray( $filter, $params = null, $ss_filters = null )
    {
        // interpret any parameter values as lookups
        $params = static::interpretRecordValues( $params );

        // build filter array if necessary, add server-side filters if necessary
        if ( !is_array( $filter ) )
        {
//            Session::replaceLookups( $filter );
            $_criteria = static::buildFilterArray( $filter, $params );
        }
        else
        {
            $_criteria = $filter;
        }
        $_serverCriteria = static::buildSSFilterArray( $ss_filters );
        if ( !empty( $_serverCriteria ) )
        {
            $_criteria = ( !empty( $_criteria ) ) ? array($_criteria, $_serverCriteria) : $_serverCriteria;
        }

        return $_criteria;
    }

    protected static function buildSSFilterArray( $ss_filters )
    {
        if ( empty( $ss_filters ) )
        {
            return null;
        }

        // build the server side criteria
        $_filters = ArrayUtils::get( $ss_filters, 'filters' );
        if ( empty( $_filters ) )
        {
            return null;
        }

        $_criteria = array();
        $_combiner = ArrayUtils::get( $ss_filters, 'filter_op', 'and' );
        foreach ( $_filters as $_filter )
        {
            $_name = ArrayUtils::get( $_filter, 'name' );
            $_op = ArrayUtils::get( $_filter, 'operator' );
            if ( empty( $_name ) || empty( $_op ) )
            {
                // log and bail
                throw new InternalServerErrorException( 'Invalid server-side filter configuration detected.' );
            }

            $_value = ArrayUtils::get( $_filter, 'value' );
            $_value = static::interpretFilterValue( $_value );

            $_criteria[] = static::buildFilterArray( "$_name $_op $_value" );
        }

        if ( 1 == count( $_criteria ) )
        {
            return $_criteria[0];
        }

        switch ( strtoupper( $_combiner ) )
        {
            case 'AND':
                break;
            case 'OR':
                $_criteria = array('split' => $_criteria);
                break;
            default:
                // log and bail
                throw new InternalServerErrorException( 'Invalid server-side filter configuration detected.' );
        }

        return $_criteria;
    }

    /**
     * @param string|array $filter Filter for querying records by
     * @param null|array   $params
     *
     * @throws BadRequestException
     * @return array
     */
    protected static function buildFilterArray( $filter, $params = null )
    {
        if ( empty( $filter ) )
        {
            return array();
        }

        if ( is_array( $filter ) )
        {
            return $filter; // assume they know what they are doing
        }

        $_search = array(' or ', ' and ', ' nor ');
        $_replace = array(' || ', ' && ', ' NOR ');
        $filter = trim( str_ireplace( $_search, $_replace, $filter ) );

        // handle logical operators first
        $_ops = array_map( 'trim', explode( ' && ', $filter ) );
        if ( count( $_ops ) > 1 )
        {
            $_parts = array();
            foreach ( $_ops as $_op )
            {
                $_parts = array_merge( $_parts, static::buildFilterArray( $_op, $params ) );
            }

            return $_parts;
        }

        $_ops = array_map( 'trim', explode( ' || ', $filter ) );
        if ( count( $_ops ) > 1 )
        {
            // need to split this into multiple queries
            throw new BadRequestException( 'OR logical comparison not currently supported on DynamoDb.' );
        }

        $_ops = array_map( 'trim', explode( ' NOR ', $filter ) );
        if ( count( $_ops ) > 1 )
        {
            throw new BadRequestException( 'NOR logical comparison not currently supported on DynamoDb.' );
        }

        // handle negation operator, i.e. starts with NOT?
        if ( 0 == substr_compare( $filter, 'not ', 0, 4, true ) )
        {
            throw new BadRequestException( 'NOT logical comparison not currently supported on DynamoDb.' );
        }

        // the rest should be comparison operators
        $_search = array(
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
        $_replace = array(
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
        $filter = trim( str_ireplace( $_search, $_replace, $filter ) );

        // Note: order matters, watch '='
        $_sqlOperators = array(
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
        $_dynamoOperators = array(
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

        foreach ( $_sqlOperators as $_key => $_sqlOp )
        {
            $_ops = array_map( 'trim', explode( $_sqlOp, $filter ) );
            if ( count( $_ops ) > 1 )
            {
//                $_field = $_ops[0];
                $_val = static::_determineValue( $_ops[1], $params );
                $_dynamoOp = $_dynamoOperators[$_key];
                switch ( $_dynamoOp )
                {
                    case ComparisonOperator::NE:
                        if ( 0 == strcasecmp( 'null', $_ops[1] ) )
                        {
                            return array(
                                $_ops[0] => array(
                                    'ComparisonOperator' => ComparisonOperator::NOT_NULL
                                )
                            );
                        }

                        return array(
                            $_ops[0] => array(
                                'AttributeValueList' => $_val,
                                'ComparisonOperator' => $_dynamoOp
                            )
                        );

                    case ComparisonOperator::EQ:
                        if ( 0 == strcasecmp( 'null', $_ops[1] ) )
                        {
                            return array(
                                $_ops[0] => array(
                                    'ComparisonOperator' => ComparisonOperator::NULL
                                )
                            );
                        }

                        return array(
                            $_ops[0] => array(
                                'AttributeValueList' => $_val,
                                'ComparisonOperator' => ComparisonOperator::EQ
                            )
                        );

                    case ComparisonOperator::CONTAINS:
//			WHERE name LIKE "%Joe%"	use CONTAINS "Joe"
//			WHERE name LIKE "Joe%"	use BEGINS_WITH "Joe"
//			WHERE name LIKE "%Joe"	not supported
                        $_val = $_ops[1];
                        $_type = Type::S;
                        if ( trim( $_val, "'\"" ) === $_val )
                        {
                            $_type = Type::N;
                        }

                        $_val = trim( $_val, "'\"" );
                        if ( '%' == $_val[strlen( $_val ) - 1] )
                        {
                            if ( '%' == $_val[0] )
                            {
                                return array(
                                    $_ops[0] => array(
                                        'AttributeValueList' => array($_type => trim( $_val, '%' )),
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    )
                                );
                            }
                            else
                            {
                                throw new BadRequestException( 'ENDS_WITH currently not supported in DynamoDb.' );
                            }
                        }
                        else
                        {
                            if ( '%' == $_val[0] )
                            {
                                return array(
                                    $_ops[0] => array(
                                        'AttributeValueList' => array($_type => trim( $_val, '%' )),
                                        'ComparisonOperator' => ComparisonOperator::BEGINS_WITH
                                    )
                                );
                            }
                            else
                            {
                                return array(
                                    $_ops[0] => array(
                                        'AttributeValueList' => array($_type => trim( $_val, '%' )),
                                        'ComparisonOperator' => ComparisonOperator::CONTAINS
                                    )
                                );
                            }
                        }

                    default:
                        return array(
                            $_ops[0] => array(
                                'AttributeValueList' => $_val,
                                'ComparisonOperator' => $_dynamoOp
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
    private static function _determineValue( $value, $replacements = null )
    {
        // process parameter replacements
        if ( is_string( $value ) && !empty( $value ) && ( ':' == $value[0] ) )
        {
            if ( isset( $replacements, $replacements[$value] ) )
            {
                $value = $replacements[$value];
            }
        }

        if ( trim( $value, "'\"" ) !== $value )
        {
            return array(array(Type::S => trim( $value, "'\"" ))); // meant to be a string
        }

        if ( is_numeric( $value ) )
        {
            $value = ( $value == strval( intval( $value ) ) ) ? intval( $value ) : floatval( $value );

            return array(array(Type::N => $value));
        }

        if ( 0 == strcasecmp( $value, 'true' ) )
        {
            return array(array(Type::N => 1));
        }

        if ( 0 == strcasecmp( $value, 'false' ) )
        {
            return array(array(Type::N => 0));
        }

        return $value;
    }

    /**
     * {@inheritdoc}
     */
    protected function initTransaction( $handle = null )
    {
        return parent::initTransaction( $handle );
    }

    /**
     * {@inheritdoc}
     */
    protected function addToTransaction( $record = null, $id = null, $extras = null, $rollback = false, $continue = false, $single = false )
    {
        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_fieldsInfo = ArrayUtils::get( $extras, 'fields_info' );
        $_idsInfo = ArrayUtils::get( $extras, 'ids_info' );
        $_idFields = ArrayUtils::get( $extras, 'id_fields' );
        $_updates = ArrayUtils::get( $extras, 'updates' );

        $_out = array();
        switch ( $this->getAction() )
        {
            case Verbs::POST:
                $_parsed = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters );
                if ( empty( $_parsed ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                $_native = $this->_formatAttributes( $_parsed );

                /*$_result = */
                $this->service->getConnection()->putItem(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Item'                  => $_native,
                        'Expected'              => array($_idFields[0] => array('Exists' => false))
                    )
                );

                if ( $rollback )
                {
                    $_key = static::_buildKey( $_idsInfo, $record );
                    $this->addToRollback( $_key );
                }

                $_out = static::cleanRecord( $record, $_fields, $_idFields );
                break;
            case Verbs::PUT:
                if ( !empty( $_updates ) )
                {
                    // only update by full records can use batching
                    $_updates[$_idFields[0]] = $id;
                    $record = $_updates;
                }

                $_parsed = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters, true );
                if ( empty( $_parsed ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                $_native = $this->_formatAttributes( $_parsed );

                if ( !$continue && !$rollback )
                {
                    return parent::addToTransaction( $_native, $id );
                }

                $_options = ( $rollback ) ? ReturnValue::ALL_OLD : ReturnValue::NONE;
                $_result = $this->service->getConnection()->putItem(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Item'                  => $_native,
                        //                            'Expected'     => $_expected,
                        'ReturnValues'          => $_options
                    )
                );

                if ( $rollback )
                {
                    $_temp = ArrayUtils::get( $_result, 'Attributes' );
                    if ( !empty( $_temp ) )
                    {
                        $this->addToRollback( $_temp );
                    }
                }

                $_out = static::cleanRecord( $record, $_fields, $_idFields );
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                if ( !empty( $_updates ) )
                {
                    $_updates[$_idFields[0]] = $id;
                    $record = $_updates;
                }

                $_parsed = $this->parseRecord( $record, $_fieldsInfo, $_ssFilters, true );
                if ( empty( $_parsed ) )
                {
                    throw new BadRequestException( 'No valid fields were found in record.' );
                }

                $_key = static::_buildKey( $_idsInfo, $_parsed, true );
                $_native = $this->_formatAttributes( $_parsed, true );

                // simple insert request
                $_options = ( $rollback ) ? ReturnValue::ALL_OLD : ReturnValue::ALL_NEW;

                $_result = $this->service->getConnection()->updateItem(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Key'                   => $_key,
                        'AttributeUpdates'      => $_native,
                        'ReturnValues'          => $_options
                    )
                );

                $_temp = ArrayUtils::get( $_result, 'Attributes', array() );
                if ( $rollback )
                {
                    $this->addToRollback( $_temp );

                    // merge old record with new changes
                    $_new = array_merge( $this->_unformatAttributes( $_temp ), $_updates );
                    $_out = static::cleanRecord( $_new, $_fields, $_idFields );
                }
                else
                {
                    $_temp = $this->_unformatAttributes( $_temp );
                    $_out = static::cleanRecord( $_temp, $_fields, $_idFields );
                }
                break;

            case Verbs::DELETE:
                if ( !$continue && !$rollback )
                {
                    return parent::addToTransaction( null, $id );
                }

                $_record = array($_idFields[0] => $id);
                $_key = static::_buildKey( $_idsInfo, $_record );

                $_result = $this->service->getConnection()->deleteItem(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Key'                   => $_key,
                        'ReturnValues'          => ReturnValue::ALL_OLD,
                    )
                );

                $_temp = ArrayUtils::get( $_result, 'Attributes', array() );

                if ( $rollback )
                {
                    $this->addToRollback( $_temp );
                }

                $_temp = $this->_unformatAttributes( $_temp );
                $_out = static::cleanRecord( $_temp, $_fields, $_idFields );
                break;

            case Verbs::GET:
                $_record = array($_idFields[0] => $id);
                $_key = static::_buildKey( $_idsInfo, $_record );
                $_scanProperties = array(
                    static::TABLE_INDICATOR => $this->_transactionTable,
                    'Key'                   => $_key,
                    'ConsistentRead'        => true,
                );

                $_fields = static::_buildAttributesToGet( $_fields, $_idFields );
                if ( !empty( $_fields ) )
                {
                    $_scanProperties['AttributesToGet'] = $_fields;
                }

                $_result = $this->service->getConnection()->getItem( $_scanProperties );
                $_result = $_result['Item'];
                if (empty($_result))
                {
                    throw new NotFoundException('Record not found.');
                }

                // Grab value from the result object like an array
                $_out = $this->_unformatAttributes( $_result['Item'] );
                break;
            default:
                break;
        }

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function commitTransaction( $extras = null )
    {
        if ( empty( $this->_batchRecords ) && empty( $this->_batchIds ) )
        {
            return null;
        }

//        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_requireMore = ArrayUtils::get( $extras, 'require_more' );
        $_idsInfo = ArrayUtils::get( $extras, 'ids_info' );
        $_idFields = ArrayUtils::get( $extras, 'id_fields' );

        $_out = array();
        switch ( $this->getAction() )
        {
            case Verbs::POST:
                $_requests = array();
                foreach ( $this->_batchRecords as $_item )
                {
                    $_requests[] = array('PutRequest' => array('Item' => $_item));
                }

                /*$_result = */
                $this->service->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->_transactionTable => $_requests))
                );

                // todo check $_result['UnprocessedItems'] for 'PutRequest'

                foreach ( $this->_batchRecords as $_item )
                {
                    $_out[] = static::cleanRecord( $this->_unformatAttributes( $_item ), $_fields, $_idFields );
                }
                break;

            case Verbs::PUT:
                $_requests = array();
                foreach ( $this->_batchRecords as $_item )
                {
                    $_requests[] = array('PutRequest' => array('Item' => $_item));
                }

                /*$_result = */
                $this->service->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->_transactionTable => $_requests))
                );

                // todo check $_result['UnprocessedItems'] for 'PutRequest'

                foreach ( $this->_batchRecords as $_item )
                {
                    $_out[] = static::cleanRecord( $this->_unformatAttributes( $_item ), $_fields, $_idFields );
                }
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                throw new BadRequestException( 'Batch operation not supported for patch.' );
                break;

            case Verbs::DELETE:
                $_requests = array();
                foreach ( $this->_batchIds as $_id )
                {
                    $_record = array($_idFields[0] => $_id);
                    $_out[] = $_record;
                    $_key = static::_buildKey( $_idsInfo, $_record );
                    $_requests[] = array('DeleteRequest' => array('Key' => $_key));
                }
                if ( $_requireMore )
                {
                    $_scanProperties = array(
                        'Keys'           => $this->_batchRecords,
                        'ConsistentRead' => true,
                    );

                    $_attributes = static::_buildAttributesToGet( $_fields, $_idFields );
                    if ( !empty( $_attributes ) )
                    {
                        $_scanProperties['AttributesToGet'] = $_attributes;
                    }

                    // Get multiple items by key in a BatchGetItem request
                    $_result = $this->service->getConnection()->batchGetItem(
                        array(
                            'RequestItems' => array(
                                $this->_transactionTable => $_scanProperties
                            )
                        )
                    );

                    $_out = array();
                    $_items = $_result->getPath( "Responses/{$this->_transactionTable}" );
                    foreach ( $_items as $_item )
                    {
                        $_out[] = $this->_unformatAttributes( $_item );
                    }
                }

                /*$_result = */
                $this->service->getConnection()->batchWriteItem(
                    array('RequestItems' => array($this->_transactionTable => $_requests))
                );

                // todo check $_result['UnprocessedItems'] for 'DeleteRequest'
                break;

            case Verbs::GET:
                $_keys = array();
                foreach ( $this->_batchIds as $_id )
                {
                    $_record = array($_idFields[0] => $_id);
                    $_key = static::_buildKey( $_idsInfo, $_record );
                    $_keys[] = $_key;
                }

                $_scanProperties = array(
                    'Keys'           => $_keys,
                    'ConsistentRead' => true,
                );

                $_fields = static::_buildAttributesToGet( $_fields, $_idFields );
                if ( !empty( $_fields ) )
                {
                    $_scanProperties['AttributesToGet'] = $_fields;
                }

                // Get multiple items by key in a BatchGetItem request
                $_result = $this->service->getConnection()->batchGetItem(
                    array(
                        'RequestItems' => array(
                            $this->_transactionTable => $_scanProperties
                        )
                    )
                );

                $_items = $_result->getPath( "Responses/{$this->_transactionTable}" );
                foreach ( $_items as $_item )
                {
                    $_out[] = $this->_unformatAttributes( $_item );
                }
                break;
            default:
                break;
        }

        $this->_batchIds = array();
        $this->_batchRecords = array();

        return $_out;
    }

    /**
     * {@inheritdoc}
     */
    protected function addToRollback( $record )
    {
        return parent::addToRollback( $record );
    }

    /**
     * {@inheritdoc}
     */
    protected function rollbackTransaction()
    {
        if ( !empty( $this->_rollbackRecords ) )
        {
            switch ( $this->getAction() )
            {
                case Verbs::POST:
                    $_requests = array();
                    foreach ( $this->_rollbackRecords as $_item )
                    {
                        $_requests[] = array('DeleteRequest' => array('Key' => $_item));
                    }

                    /* $_result = */
                    $this->service->getConnection()->batchWriteItem(
                        array('RequestItems' => array($this->_transactionTable => $_requests))
                    );

                    // todo check $_result['UnprocessedItems'] for 'DeleteRequest'
                    break;

                case Verbs::PUT:
                case Verbs::PATCH:
                case Verbs::MERGE:
                case Verbs::DELETE:
                    $_requests = array();
                    foreach ( $this->_rollbackRecords as $_item )
                    {
                        $_requests[] = array('PutRequest' => array('Item' => $_item));
                    }

                    /* $_result = */
                    $this->service->getConnection()->batchWriteItem(
                        array('RequestItems' => array($this->_transactionTable => $_requests))
                    );

                    // todo check $_result['UnprocessedItems'] for 'PutRequest'
                    break;

                default:
                    break;
            }

            $this->_rollbackRecords = array();
        }

        return true;
    }
}