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

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Library\Utility\Enums\Verbs;
use DreamFactory\Library\Utility\Inflector;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
use DreamFactory\Rave\Resources\BaseDbTableResource;
use DreamFactory\Rave\Aws\Services\SimpleDb;
use DreamFactory\Rave\Utility\DbUtilities;

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
    public function listResources($fields = null)
    {
//        $refresh = $this->request->queryBool('refresh');

        $_names = $this->service->getTables();

        if (empty($fields))
        {
            return ['resource' => $_names];
        }

        $_extras = DbUtilities::getSchemaExtrasForTables( $this->service->getServiceId(), $_names, false, 'table,label,plural' );

        $_tables = [];
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

            $_tables[] = ['name' => $name, 'label' => $label, 'plural' => $plural];
        }

        return $this->makeResourceList($_tables, 'name', $fields, 'resource' );
    }

    /**
     * {@inheritdoc}
     */
    public function retrieveRecordsByFilter( $table, $filter = null, $params = array(), $extras = array() )
    {
        $_idField = ArrayUtils::get( $extras, 'id_field', static::DEFAULT_ID_FIELD );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );

        $_fields = static::_buildAttributesToGet( $_fields );

        $_select = 'select ';
        $_select .= ( empty( $_fields ) ) ? '*' : $_fields;
        $_select .= ' from ' . $table;

        $_parsedFilter = static::buildCriteriaArray( $filter, $params, $_ssFilters );
        if ( !empty( $_parsedFilter ) )
        {
            $_select .= ' where ' . $_parsedFilter;
        }

        $_order = ArrayUtils::get( $extras, 'order' );
        if ( $_order > 0 )
        {
            $_select .= ' order by ' . $_order;
        }

        $_limit = ArrayUtils::get( $extras, 'limit' );
        if ( $_limit > 0 )
        {
            $_select .= ' limit ' . $_limit;
        }

        try
        {
            $_result = $this->service->getConnection()->select( array('SelectExpression' => $_select, 'ConsistentRead' => true) );
            $_items = ArrayUtils::clean( $_result['Items'] );

            $_out = array();
            foreach ( $_items as $_item )
            {
                $_attributes = ArrayUtils::get( $_item, 'Attributes' );
                $_name = ArrayUtils::get( $_item, $_idField );
                $_out[] = array_merge(
                    static::_unformatAttributes( $_attributes ),
                    array($_idField => $_name)
                );
            }

            return $_out;
        }
        catch ( \Exception $_ex )
        {
            throw new InternalServerErrorException( "Failed to filter records from '$table'.\n{$_ex->getMessage()}" );
        }
    }

    protected function getIdsInfo( $table, $fields_info = null, &$requested_fields = null, $requested_types = null )
    {
        if ( empty( $requested_fields ) )
        {
            $requested_fields = array(static::DEFAULT_ID_FIELD); // can only be this
            $_ids = array(
                array('name' => static::DEFAULT_ID_FIELD, 'type' => 'string', 'required' => true),
            );
        }
        else
        {
            $_ids = array(
                array('name' => $requested_fields, 'type' => 'string', 'required' => true),
            );
        }

        return $_ids;
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

    protected static function _formatValue( $value )
    {
        if ( is_string( $value ) )
        {
            return $value;
        }
        if ( is_array( $value ) )
        {
            return '#DFJ#' . json_encode( $value );
        }
        if ( is_bool( $value ) )
        {
            return '#DFB#' . strval( $value );
        }
        if ( is_float( $value ) )
        {
            return '#DFF#' . strval( $value );
        }
        if ( is_int( $value ) )
        {
            return '#DFI#' . strval( $value );
        }

        return $value;
    }

    protected static function _unformatValue( $value )
    {
        if ( 0 == substr_compare( $value, '#DFJ#', 0, 5 ) )
        {
            return json_decode( substr( $value, 5 ) );
        }
        if ( 0 == substr_compare( $value, '#DFB#', 0, 5 ) )
        {
            return (bool)substr( $value, 5 );
        }
        if ( 0 == substr_compare( $value, '#DFF#', 0, 5 ) )
        {
            return floatval( substr( $value, 5 ) );
        }
        if ( 0 == substr_compare( $value, '#DFI#', 0, 5 ) )
        {
            return intval( substr( $value, 5 ) );
        }

        return $value;
    }

    /**
     * @param array $record
     * @param bool  $replace
     *
     * @return array
     */
    protected static function _formatAttributes( $record, $replace = false )
    {
        $_out = array();
        if ( !empty( $record ) )
        {
            foreach ( $record as $_name => $_value )
            {
                if ( ArrayUtils::isArrayNumeric( $_value ) )
                {
                    foreach ( $_value as $_key => $_part )
                    {
                        $_part = static::_formatValue( $_part );
                        if ( 0 == $_key )
                        {
                            $_out[] = array('Name' => $_name, 'Value' => $_part, 'Replace' => $replace);
            }
                        else
                        {
                            $_out[] = array('Name' => $_name, 'Value' => $_part);
        }
                    }

                }
                else
                {
                    $_value = static::_formatValue( $_value );
                    $_out[] = array('Name' => $_name, 'Value' => $_value, 'Replace' => $replace);
                }
            }
        }

        return $_out;
    }

    /**
     * @param array $record
     *
     * @return array
     */
    protected static function _unformatAttributes( $record )
    {
        $_out = array();
        if ( !empty( $record ) )
        {
            foreach ( $record as $_attribute )
            {
                $_name = ArrayUtils::get( $_attribute, 'Name' );
                if ( empty( $_name ) )
                    {
                    continue;
                    }

                $_value = ArrayUtils::get( $_attribute, 'Value' );
                if ( isset( $_out[$_name] ) )
                    {
                    $_temp = $_out[$_name];
                    if ( is_array( $_temp ) )
                    {
                        $_temp[] = static::_unformatValue( $_value );
                        $_value = $_temp;
                    }
                    else
                    {
                        $_value = array($_temp, static::_unformatValue( $_value ));
                        }
                }
                        else
                        {
                    $_value = static::_unformatValue( $_value );
                        }
                $_out[$_name] = $_value;
                    }
        }

                    return $_out;
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

    protected static function buildCriteriaArray( $filter, $params = null, $ss_filters = null )
    {
        // interpret any parameter values as lookups
        $params = static::interpretRecordValues( $params );

        // build filter array if necessary, add server-side filters if necessary
        $_criteria = static::_parseFilter( $filter, $params );
        $_serverCriteria = static::buildSSFilterArray( $ss_filters );
        if ( !empty( $_serverCriteria ) )
        {
            $_criteria =
                ( !empty( $_criteria ) ) ? '(' . $_serverCriteria . ') AND (' . $_criteria . ')' : $_serverCriteria;
        }

        return $_criteria;
    }

    protected static function buildSSFilterArray( $ss_filters )
    {
        if ( empty( $ss_filters ) )
        {
            return '';
        }

        // build the server side criteria
        $_filters = ArrayUtils::get( $ss_filters, 'filters' );
        if ( empty( $_filters ) )
        {
            return '';
        }

        $_combiner = ArrayUtils::get( $ss_filters, 'filter_op', 'and' );
        switch ( strtoupper( $_combiner ) )
        {
            case 'AND':
            case 'OR':
                break;
            default:
                // log and bail
                throw new InternalServerErrorException( 'Invalid server-side filter configuration detected.' );
        }

        $_criteria = '';
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

            $_temp = static::_parseFilter( "$_name $_op $_value" );
            if ( !empty( $_criteria ) )
        {
                $_criteria .= " $_combiner ";
        }
            $_criteria .= $_temp;
        }

        return $_criteria;
    }

    /**
     * @param string|array $filter Filter for querying records by
     * @param null         $params
     *
     * @throws BadRequestException
     * @return array
     */
    protected static function _parseFilter( $filter, $params = null )
    {
        if ( empty( $filter ) )
        {
            return $filter;
        }

        if ( is_array( $filter ) )
        {
            throw new BadRequestException( 'Filtering in array format is not currently supported on SimpleDb.' );
        }

//        Session::replaceLookups( $filter );

        // handle logical operators first
        $_search = array(' || ', ' && ');
        $_replace = array(' or ', ' and ');
        $filter = trim( str_ireplace( $_search, $_replace, $filter ) );

        // the rest should be comparison operators
        $_search = array(' eq ', ' ne ', ' gte ', ' lte ', ' gt ', ' lt ');
        $_replace = array(' = ', ' != ', ' >= ', ' <= ', ' > ', ' < ');
        $filter = trim( str_ireplace( $_search, $_replace, $filter ) );

        // check for x = null
        $filter = str_ireplace( ' = null', ' is null', $filter );
        // check for x != null
        $filter = str_ireplace( ' != null', ' is not null', $filter );

        return $filter;
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
                $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $_native,
                        'Expected'              => array($_idFields[0] => array('Exists' => false))
                    )
                );

                if ( $rollback )
                {
                    $this->addToRollback( $id );
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

                $_native = $this->_formatAttributes( $_parsed, true );
//                $_batched = array( 'Name' => $id, 'Attributes' => $_native );

                if ( !$continue && !$rollback )
                {
                    $_batched = array('Name' => $id, 'Attributes' => $_native);

                    return parent::addToTransaction( $_batched, $id );
                }

                $_result = $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $_native,
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

                $_native = $this->_formatAttributes( $_parsed, true );

                $_result = $this->service->getConnection()->putAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'ItemName'              => $id,
                        'Attributes'            => $_native,
                    )
                );

                if ( $rollback )
                {
                    $_old = ArrayUtils::get( $_result, 'Attributes', array() );
                    $this->addToRollback( $_old );
                }

                $_out = static::cleanRecord( $record, $_fields, $_idFields );
                break;

            case Verbs::DELETE:
                if ( !$continue && !$rollback )
                {
                    return parent::addToTransaction( null, $id );
                }

                $_result = $this->service->getConnection()->deleteAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'ItemName'              => $id
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
                $_scanProperties = array(
                    static::TABLE_INDICATOR => $this->_transactionTable,
                    'ItemName'              => $id,
                    'ConsistentRead'        => true,
                );

                $_fields = static::_buildAttributesToGet( $_fields, $_idFields );
                if ( !empty( $_fields ) )
                {
                    $_scanProperties['AttributeNames'] = $_fields;
                }

                $_result = $this->service->getConnection()->getAttributes( $_scanProperties );

                $_out = array_merge(
                    static::_unformatAttributes( $_result['Attributes'] ),
                    array($_idFields[0] => $id)
                );
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

        $_ssFilters = ArrayUtils::get( $extras, 'ss_filters' );
        $_fields = ArrayUtils::get( $extras, 'fields' );
        $_requireMore = ArrayUtils::get( $extras, 'require_more' );
        $_idsInfo = ArrayUtils::get( $extras, 'ids_info' );
        $_idFields = ArrayUtils::get( $extras, 'id_fields' );

        $_out = array();
        switch ( $this->getAction() )
        {
            case Verbs::POST:
                $_result = $this->service->getConnection()->batchPutAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Items'                 => $this->_batchRecords,
                    )
                );

                $_out = static::cleanRecords( $this->_batchRecords, $_fields, $_idFields );
                break;

            case Verbs::PUT:
                $_result = $this->service->getConnection()->batchPutAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Items'                 => $this->_batchRecords,
                    )
                );

                $_out = static::cleanRecords( $this->_batchRecords, $_fields, $_idFields );
                break;

            case Verbs::MERGE:
            case Verbs::PATCH:
                throw new BadRequestException( 'Batch operation not supported for patch.' );
                break;

            case Verbs::DELETE:
                if ( $_requireMore )
                {
                    $_fields = static::_buildAttributesToGet( $_fields );

                    $_select = 'select ';
                    $_select .= ( empty( $_fields ) ) ? '*' : $_fields;
                    $_select .= ' from ' . $this->_transactionTable;

                    $_filter = "itemName() in ('" . implode( "','", $this->_batchIds ) . "')";
                    $_parsedFilter = static::buildCriteriaArray( $_filter, null, $_ssFilters );
                    if ( !empty( $_parsedFilter ) )
                    {
                        $_select .= ' where ' . $_parsedFilter;
                    }

                    $_result =
                        $this->service->getConnection()->select( array('SelectExpression' => $_select, 'ConsistentRead' => true) );
                    $_items = ArrayUtils::clean( $_result['Items'] );

                    $_out = array();
                    foreach ( $_items as $_item )
                    {
                        $_attributes = ArrayUtils::get( $_item, 'Attributes' );
                        $_name = ArrayUtils::get( $_item, static::DEFAULT_ID_FIELD );
                        $_out[] = array_merge(
                            static::_unformatAttributes( $_attributes ),
                            array($_idFields[0] => $_name)
                        );
                    }
                }
                else
                {
                    $_out = static::cleanRecords( $this->_batchRecords, $_fields, $_idFields );
                }

                $_items = array();
                foreach ( $this->_batchIds as $_id )
                {
                    $_items[] = array('Name' => $_id);
                }
                /*$_result = */
                $this->service->getConnection()->batchDeleteAttributes(
                    array(
                        static::TABLE_INDICATOR => $this->_transactionTable,
                        'Items'                 => $_items
                    )
                );

                // todo check $_result['UnprocessedItems'] for 'DeleteRequest'
                break;

            case Verbs::GET:
                $_fields = static::_buildAttributesToGet( $_fields );

                $_select = 'select ';
                $_select .= ( empty( $_fields ) ) ? '*' : $_fields;
                $_select .= ' from ' . $this->_transactionTable;

                $_filter = "itemName() in ('" . implode( "','", $this->_batchIds ) . "')";
                $_parsedFilter = static::buildCriteriaArray( $_filter, null, $_ssFilters );
                if ( !empty( $_parsedFilter ) )
                {
                    $_select .= ' where ' . $_parsedFilter;
                }

                $_result = $this->service->getConnection()->select( array('SelectExpression' => $_select, 'ConsistentRead' => true) );
                $_items = ArrayUtils::clean( $_result['Items'] );

                $_out = array();
                foreach ( $_items as $_item )
                {
                    $_attributes = ArrayUtils::get( $_item, 'Attributes' );
                    $_name = ArrayUtils::get( $_item, 'Name' );
                    $_out[] = array_merge(
                        static::_unformatAttributes( $_attributes ),
                        array($_idFields[0] => $_name)
                    );
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

                    /* $_result = */
                    $this->service->getConnection()->batchDeleteAttributes(
                        array(
                            static::TABLE_INDICATOR => $this->_transactionTable,
                            'Items'                 => $this->_rollbackRecords
                        )
                    );
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

                    $this->service->getConnection()->batchPutAttributes(
                        array(
                            static::TABLE_INDICATOR => $this->_transactionTable,
                            'Items'                 => $this->_batchRecords,
                        )
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