<?php
namespace DreamFactory\Core\Aws\Database\Schema;

use DreamFactory\Core\Database\Schema\ColumnSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbResourceTypes;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\SqlDb\Database\Schema\SqlSchema;

/**
 * Schema is the class for retrieving metadata information from a AWS Redshift database.
 */
class RedshiftSchema extends SqlSchema
{
    const DEFAULT_SCHEMA = 'public';

    /**
     * @inheritdoc
     */
    public function getDefaultSchema()
    {
        return static::DEFAULT_SCHEMA;
    }

    /**
     * @inheritdoc
     */
    public function getSupportedResourceTypes()
    {
        return [
            DbResourceTypes::TYPE_TABLE,
            DbResourceTypes::TYPE_TABLE_FIELD,
            DbResourceTypes::TYPE_TABLE_CONSTRAINT,
            DbResourceTypes::TYPE_TABLE_RELATIONSHIP,
            DbResourceTypes::TYPE_VIEW,
        ];
    }

    protected function translateSimpleColumnTypes(array &$info)
    {
        // override this in each schema class
        $type = (isset($info['type'])) ? $info['type'] : null;
        switch ($type) {
            // some types need massaging, some need other required properties
            case 'pk':
            case DbSimpleTypes::TYPE_ID:
                $info['type'] = 'integer';
                $info['allow_null'] = false;
                $info['auto_increment'] = true;
                $info['is_primary_key'] = true;
                break;

            case 'fk':
            case DbSimpleTypes::TYPE_REF:
                $info['type'] = 'integer';
                $info['is_foreign_key'] = true;
                // check foreign tables
                break;

            case DbSimpleTypes::TYPE_DATETIME:
                $info['type'] = 'timestamp';
                break;

            case DbSimpleTypes::TYPE_TIMESTAMP_ON_CREATE:
            case DbSimpleTypes::TYPE_TIMESTAMP_ON_UPDATE:
                $info['type'] = 'timestamp';
                $default = (isset($info['default'])) ? $info['default'] : null;
                if (!isset($default)) {
                    $default = 'CURRENT_TIMESTAMP';
                    // ON UPDATE CURRENT_TIMESTAMP not supported by PostgreSQL, use triggers
                    $info['default'] = $default;
                }
                break;

            case DbSimpleTypes::TYPE_USER_ID:
            case DbSimpleTypes::TYPE_USER_ID_ON_CREATE:
            case DbSimpleTypes::TYPE_USER_ID_ON_UPDATE:
                $info['type'] = 'integer';
                break;

            case 'int':
                $info['type'] = 'integer';
                break;

            case DbSimpleTypes::TYPE_FLOAT:
                $info['type'] = 'real';
                break;

            case DbSimpleTypes::TYPE_DOUBLE:
                $info['type'] = 'double precision';
                break;

            case DbSimpleTypes::TYPE_MONEY: // not supported, use decimal
                $info['type'] = 'decimal';
                break;

            case DbSimpleTypes::TYPE_STRING:
                $fixed =
                    (isset($info['fixed_length'])) ? filter_var($info['fixed_length'], FILTER_VALIDATE_BOOLEAN) : false;
                $national =
                    (isset($info['supports_multibyte'])) ? filter_var($info['supports_multibyte'],
                        FILTER_VALIDATE_BOOLEAN) : false;
                if ($fixed) {
                    $info['type'] = ($national) ? 'national char' : 'char';
                } elseif ($national) {
                    $info['type'] = 'national varchar';
                } else {
                    $info['type'] = 'varchar';
                }
                break;

            case DbSimpleTypes::TYPE_BINARY:
                $info['type'] = 'varchar';
                break;
        }
    }

    protected function validateColumnSettings(array &$info)
    {
        // override this in each schema class
        $type = (isset($info['type'])) ? $info['type'] : null;
        switch ($type) {
            // some types need massaging, some need other required properties
            case 'boolean':
                $default = (isset($info['default'])) ? $info['default'] : null;
                if (isset($default)) {
                    // convert to bit 0 or 1, where necessary
                    $info['default'] = (filter_var($default, FILTER_VALIDATE_BOOLEAN)) ? 'TRUE' : 'FALSE';
                }
                break;

            case 'smallint':
            case 'integer':
            case 'int':
            case 'bigint':
                if (!isset($info['type_extras'])) {
                    $length =
                        (isset($info['length']))
                            ? $info['length']
                            : ((isset($info['precision'])) ? $info['precision']
                            : null);
                    if (!empty($length)) {
                        $info['type_extras'] = "($length)"; // sets the viewable length
                    }
                }

                $default = (isset($info['default'])) ? $info['default'] : null;
                if (isset($default) && is_numeric($default)) {
                    $info['default'] = intval($default);
                }
                break;

            case 'decimal':
            case 'numeric':
            case 'real':
            case 'double precision':
                if (!isset($info['type_extras'])) {
                    $length =
                        (isset($info['length']))
                            ? $info['length']
                            : ((isset($info['precision'])) ? $info['precision']
                            : null);
                    if (!empty($length)) {
                        $scale =
                            (isset($info['decimals']))
                                ? $info['decimals']
                                : ((isset($info['scale'])) ? $info['scale']
                                : null);
                        $info['type_extras'] = (!empty($scale)) ? "($length,$scale)" : "($length)";
                    }
                }

                $default = (isset($info['default'])) ? $info['default'] : null;
                if (isset($default) && is_numeric($default)) {
                    $info['default'] = floatval($default);
                }
                break;

            case 'char':
            case 'national char':
                $length = (isset($info['length'])) ? $info['length'] : ((isset($info['size'])) ? $info['size'] : null);
                if (isset($length)) {
                    $info['type_extras'] = "($length)";
                }
                break;

            case 'varchar':
            case 'national varchar':
                $length = (isset($info['length'])) ? $info['length'] : ((isset($info['size'])) ? $info['size'] : null);
                if (isset($length)) {
                    $info['type_extras'] = "($length)";
                } else // requires a max length
                {
                    $info['type_extras'] = '(' . static::DEFAULT_STRING_MAX_SIZE . ')';
                }
                break;

            case 'time':
            case 'timestamp':
                $length = (isset($info['length'])) ? $info['length'] : ((isset($info['size'])) ? $info['size'] : null);
                if (isset($length)) {
                    $info['type_extras'] = "($length)";
                }
                break;
        }
    }

    /**
     * @param array $info
     *
     * @return string
     * @throws \Exception
     */
    protected function buildColumnDefinition(array $info)
    {
        $type = (isset($info['type'])) ? $info['type'] : null;
        $typeExtras = (isset($info['type_extras'])) ? $info['type_extras'] : null;

        $definition = $type . $typeExtras;

        $allowNull = (isset($info['allow_null'])) ? filter_var($info['allow_null'], FILTER_VALIDATE_BOOLEAN) : false;
        $definition .= ($allowNull) ? ' NULL' : ' NOT NULL';

        $default = (isset($info['default'])) ? $info['default'] : null;
        if (isset($default)) {
            $quoteDefault =
                (isset($info['quote_default'])) ? filter_var($info['quote_default'], FILTER_VALIDATE_BOOLEAN) : false;
            if ($quoteDefault) {
                $default = "'" . $default . "'";
            }

            $definition .= ' DEFAULT ' . $default;
        }

        $auto = (isset($info['auto_increment'])) ? filter_var($info['auto_increment'], FILTER_VALIDATE_BOOLEAN) : false;
        if ($auto) {
            $definition .= ' IDENTITY(1,1)';
        }

        if (isset($info['is_primary_key']) && filter_var($info['is_primary_key'], FILTER_VALIDATE_BOOLEAN)) {
            $definition .= ' PRIMARY KEY';
        } elseif (isset($info['is_unique']) && filter_var($info['is_unique'], FILTER_VALIDATE_BOOLEAN)) {
            $definition .= ' UNIQUE';
        }

        return $definition;
    }

    /**
     * Resets the sequence value of a table's primary key.
     * The sequence will be reset such that the primary key of the next new row inserted
     * will have the specified value or max value of a primary key plus one (i.e. sequence trimming).
     *
     * @param TableSchema  $table   the table schema whose primary key sequence will be reset
     * @param integer|null $value   the value for the primary key of the next new row inserted.
     *                              If this is not set, the next new row's primary key will have the max value of a
     *                              primary key plus one (i.e. sequence trimming).
     *
     */
    public function resetSequence($table, $value = null)
    {
        if ($table->sequenceName === null) {
            return;
        }
        $sequence = '"' . $table->sequenceName . '"';
        if (strpos($sequence, '.') !== false) {
            $sequence = str_replace('.', '"."', $sequence);
        }
        if ($value !== null) {
            $value = (int)$value;
        } else {
            $value = "(SELECT COALESCE(MAX(\"{$table->primaryKey}\"),0) FROM {$table->quotedName})+1";
        }
        $this->connection->statement("SELECT SETVAL('$sequence',$value,false)");
    }

    /**
     * @inheritdoc
     */
    protected function loadTableColumns(TableSchema $table)
    {
        $sql = <<<EOD
SELECT a.attname AS "name", LOWER(format_type(a.atttypid, a.atttypmod)) AS db_type, a.attnotnull, a.atthasdef,
    pg_catalog.pg_get_expr(d.adbin,d.adrelid) as default,
	pg_catalog.col_description(a.attrelid, a.attnum) AS comment, 
	CASE c.contype WHEN 'p' THEN true ELSE false END AS is_primary_key,
	CASE c.contype WHEN 'u' THEN true ELSE false END AS is_unique,
	CASE c.contype WHEN 'f' THEN true ELSE false END AS is_foreign_key,
	c.confupdtype AS ref_on_update, c.confdeltype AS ref_on_update, f.relname AS ref_table,
    (SELECT a2.attname FROM pg_attribute a2 WHERE a2.attrelid = f.oid AND a2.attnum = c.confkey[1] AND a2.attisdropped = false) AS ref_field
FROM pg_attribute a 
LEFT JOIN pg_attrdef d ON a.attrelid = d.adrelid AND a.attnum = d.adnum
LEFT JOIN pg_constraint c ON c.conrelid = a.attrelid AND (a.attnum = ANY (c.conkey))
LEFT JOIN pg_class f ON f.oid = c.confrelid 
WHERE a.attnum > 0 AND NOT a.attisdropped
	AND a.attrelid = (SELECT oid FROM pg_catalog.pg_class WHERE relname=:table
		AND relnamespace = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = :schema))
ORDER BY a.attnum
EOD;

        $columns = $this->connection->select($sql, [':table' => $table->resourceName, ':schema' => $table->schemaName]);
        foreach ($columns as $column) {
            $column = array_change_key_case((array)$column, CASE_LOWER);
            $c = new ColumnSchema(array_except($column, ['atthasdef', 'default', 'attnotnull']));
            $c->quotedName = $this->quoteColumnName($c->name);
            $c->allowNull = !boolval($column['attnotnull']);
            $this->extractLimit($c, $c->dbType);
            $c->fixedLength = $this->extractFixedLength($c->dbType);
            $c->supportsMultibyte = $this->extractMultiByteSupport($c->dbType);
            $this->extractType($c, $c->dbType);
            if ($column['atthasdef']) {
                $this->extractDefault($c, $column['default']);
            }

            if ($c->isPrimaryKey) {
                if ($c->autoIncrement) {
                    $table->sequenceName = array_get($column, 'sequence', $c->name);
                    if ((DbSimpleTypes::TYPE_INTEGER === $c->type)) {
                        $c->type = DbSimpleTypes::TYPE_ID;
                    }
                }
                $table->addPrimaryKey($c->name);
            }
            $table->addColumn($c);
        }
    }

    /**
     * @inheritdoc
     */
    protected function getTableConstraints($schema = '')
    {
        $sql = <<<EOD
SELECT
  o.conname AS constraint_name,
  (SELECT nspname FROM pg_namespace WHERE oid=m.relnamespace) AS table_schema,
  m.relname AS table_name,
  (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = m.oid AND a.attnum = o.conkey[1] AND a.attisdropped = false) AS column_name,
  (SELECT nspname FROM pg_namespace WHERE oid=f.relnamespace) AS referenced_table_schema,
  f.relname AS referenced_table_name,
  (SELECT a.attname FROM pg_attribute a WHERE a.attrelid = f.oid AND a.attnum = o.confkey[1] AND a.attisdropped = false) AS referenced_column_name,
  cc.contype AS constraint_type
FROM
  pg_constraint o LEFT JOIN pg_class c ON c.oid = o.conrelid
  LEFT JOIN pg_class f ON f.oid = o.confrelid 
  LEFT JOIN pg_class m ON m.oid = o.conrelid
  LEFT JOIN pg_constraint cc ON cc.conrelid = o.conrelid AND (cc.conkey[1] = o.conkey[1]) AND (cc.contype IN ('p', 'u'))
WHERE
  o.contype = 'f' AND o.conrelid IN (SELECT oid FROM pg_class c WHERE c.relkind = 'r');
EOD;

        return $this->connection->select($sql);
    }

    public function getSchemas()
    {
        $sql = <<<MYSQL
SELECT nspname FROM pg_namespace WHERE nspname NOT IN ('information_schema','pg_catalog','pg_internal')
MYSQL;
        $rows = $this->selectColumn($sql);

        return $rows;
    }

    /**
     * @inheritdoc
     */
    protected function getTableNames($schema = '')
    {
        $sql = <<<EOD
SELECT table_name, table_schema, table_type FROM information_schema.tables WHERE table_type = 'BASE TABLE'
EOD;

        if (!empty($schema)) {
            $sql .= " AND table_schema = '$schema'";
        }

        $rows = $this->connection->select($sql);

        $names = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $schemaName = isset($row['table_schema']) ? $row['table_schema'] : '';
            $resourceName = isset($row['table_name']) ? $row['table_name'] : '';
            $internalName = $schemaName . '.' . $resourceName;
            $name = $resourceName;
            $quotedName = $this->quoteTableName($schemaName) . '.' . $this->quoteTableName($resourceName);
            $settings = compact('schemaName', 'resourceName', 'name', 'internalName','quotedName');
            $names[strtolower($name)] = new TableSchema($settings);
        }

        return $names;
    }

    /**
     * @inheritdoc
     */
    protected function getViewNames($schema = '')
    {
        $sql = <<<EOD
SELECT table_name, table_schema, table_type FROM information_schema.tables WHERE table_type = 'VIEW'
EOD;

        if (!empty($schema)) {
            $sql .= " AND table_schema = '$schema'";
        }

        $rows = $this->connection->select($sql);

        $names = [];
        foreach ($rows as $row) {
            $row = (array)$row;
            $schemaName = isset($row['table_schema']) ? $row['table_schema'] : '';
            $resourceName = isset($row['table_name']) ? $row['table_name'] : '';
            $internalName = $schemaName . '.' . $resourceName;
            $name = $resourceName;
            $quotedName = $this->quoteTableName($schemaName) . '.' . $this->quoteTableName($resourceName);
            $settings = compact('schemaName', 'resourceName', 'name', 'internalName','quotedName');
            $settings['isView'] = true;
            $names[strtolower($name)] = new TableSchema($settings);
        }

        return $names;
    }

    /**
     * Builds a SQL statement for renaming a DB table.
     *
     * @param string $table   the table to be renamed. The name will be properly quoted by the method.
     * @param string $newName the new table name. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for renaming a DB table.
     * @since 1.1.6
     */
    public function renameTable($table, $newName)
    {
        return 'ALTER TABLE ' . $this->quoteTableName($table) . ' RENAME TO ' . $this->quoteTableName($newName);
    }

    /**
     * @inheritdoc
     */
    public function alterColumn($table, $column, $definition)
    {
        /** @noinspection SqlNoDataSourceInspection */
        $sql = "ALTER TABLE $table ALTER COLUMN " . $this->quoteColumnName($column);
        if (false !== $pos = strpos($definition, ' ')) {
            $sql .= ' TYPE ' . $this->getColumnType(substr($definition, 0, $pos));
            switch (substr($definition, $pos + 1)) {
                case 'NULL':
                    $sql .= ', ALTER COLUMN ' . $this->quoteColumnName($column) . ' DROP NOT NULL';
                    break;
                case 'NOT NULL':
                    $sql .= ', ALTER COLUMN ' . $this->quoteColumnName($column) . ' SET NOT NULL';
                    break;
            }
        } else {
            $sql .= ' TYPE ' . $this->getColumnType($definition);
        }

        return $sql;
    }

    public function requiresCreateIndex($unique = false, $on_create_table = false)
    {
        return $unique; // regular indexes are not supported by redshift
    }

    /**
     * {@InheritDoc}
     */
    public function addForeignKey($name, $table, $columns, $refTable, $refColumns, $delete = null, $update = null)
    {
        // ON DELETE and ON UPDATE not supported by Redshift
        return parent::addForeignKey($name, $table, $columns, $refTable, $refColumns, null, null);
    }

    /**
     * Builds a SQL statement for creating a new index.
     *
     * @param string  $name    the name of the index. The name will be properly quoted by the method.
     * @param string  $table   the table that the new index will be created for. The table name will be properly quoted
     *                         by the method.
     * @param string  $columns the column(s) that should be included in the index. If there are multiple columns,
     *                         please separate them by commas. Each column name will be properly quoted by the method,
     *                         unless a parenthesis is found in the name.
     * @param boolean $unique  whether to add UNIQUE constraint on the created index.
     * @return string the SQL statement for creating a new index.
     * @throws BadRequestException
     */
    public function createIndex($name, $table, $columns, $unique = false)
    {
        $cols = [];
        if (is_string($columns)) {
            $columns = preg_split('/\s*,\s*/', $columns, -1, PREG_SPLIT_NO_EMPTY);
        }
        foreach ($columns as $col) {
            if (strpos($col, '(') !== false) {
                $cols[] = $col;
            } else {
                $cols[] = $this->quoteColumnName($col);
            }
        }
        if ($unique) {
            return
                'ALTER TABLE ' .
                $this->quoteTableName($table) .
                ' ADD CONSTRAINT ' .
                $this->quoteTableName($name) .
                ' UNIQUE (' .
                implode(', ', $cols) .
                ')';
        } else {
            throw new BadRequestException('Indexes not supported.');
        }
    }

    /**
     * Builds a SQL statement for dropping an index.
     *
     * @param string $name  the name of the index to be dropped. The name will be properly quoted by the method.
     * @param string $table the table whose index is to be dropped. The name will be properly quoted by the method.
     *
     * @return string the SQL statement for dropping an index.
     * @since 1.1.6
     */
    public function dropIndex($name, $table)
    {
        /** @noinspection SqlNoDataSourceInspection */
        return "ALTER TABLE {$this->quoteTableName($table)} DROP CONSTRAINT {$this->quoteTableName($name)} )";
    }

    public function typecastToNative($value, $field_info, $allow_null = true)
    {
        $value = parent::typecastToNative($value, $field_info, $allow_null);

        switch ($field_info->type) {
            case DbSimpleTypes::TYPE_BOOLEAN:
                $value = ($value ? 'TRUE' : 'FALSE');
                break;
        }

        return $value;
    }

    protected function formatValueToPhpType($value, $type, $allow_null = true)
    {
        if (!is_null($value)) {
            switch (strtolower(strval($type))) {
                case 'int':
                case 'integer':
                    if ('' === $value) {
                        // Postgresql strangely returns "" for null integers
                        return null;
                    }
            }
        }

        return parent::formatValueToPhpType($value, $type, $allow_null);
    }

    /**
     * @inheritdoc
     */
    public function extractType(ColumnSchema $column, $dbType)
    {
        parent::extractType($column, $dbType);
        if (strpos($dbType, '[') !== false || strpos($dbType, 'char') !== false || strpos($dbType, 'text') !== false) {
            $column->type = DbSimpleTypes::TYPE_STRING;
        } elseif (preg_match('/(real|float|double)/', $dbType)) {
            $column->type = DbSimpleTypes::TYPE_DOUBLE;
        } elseif (preg_match('/(integer|oid|serial|smallint)/', $dbType)) {
            $column->type = DbSimpleTypes::TYPE_INTEGER;
        }
    }

    /**
     * Extracts size, precision and scale information from column's DB type.
     *
     * @param ColumnSchema $field
     * @param string       $dbType the column's DB type
     */
    public function extractLimit(ColumnSchema $field, $dbType)
    {
        if (strpos($dbType, '(')) {
            if (preg_match('/^time.*\((.*)\)/', $dbType, $matches)) {
                $field->precision = (int)$matches[1];
            } elseif (preg_match('/\((.*)\)/', $dbType, $matches)) {
                $values = explode(',', $matches[1]);
                $field->size = $field->precision = (int)$values[0];
                if (isset($values[1])) {
                    $field->scale = (int)$values[1];
                }
            }
        }
    }

    /**
     * Extracts the default value for the column.
     * The value is typecasted to correct PHP type.
     *
     * @param ColumnSchema $field
     * @param mixed        $defaultValue the default value obtained from metadata
     */
    public function extractDefault(ColumnSchema $field, $defaultValue)
    {
        if ($defaultValue === 'true') {
            $field->defaultValue = true;
        } elseif ($defaultValue === 'false') {
            $field->defaultValue = false;
        } elseif (0 === stripos($defaultValue, '"identity"')) {
            $field->autoIncrement = true;
        } elseif (preg_match('/^\'(.*)\'::/', $defaultValue, $matches)) {
            parent::extractDefault($field, str_replace("''", "'", $matches[1]));
        } elseif (preg_match('/^(-?\d+(\.\d*)?)(::.*)?$/', $defaultValue, $matches)) {
            parent::extractDefault($field, $matches[1]);
        } else {
            // could be a internal function call like setting uuids
            $field->defaultValue = $defaultValue;
        }
    }

    /**
     * @return mixed
     */
    public function getTimestampForSet()
    {
        return $this->connection->raw('(GETDATE())');
    }
}
