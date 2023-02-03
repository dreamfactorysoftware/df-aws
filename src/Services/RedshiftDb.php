<?php

namespace DreamFactory\Core\Aws\Services;

use DreamFactory\Core\Database\Schema\RelationSchema;
use DreamFactory\Core\Database\Schema\TableSchema;
use DreamFactory\Core\Enums\DbSimpleTypes;
use DreamFactory\Core\SqlDb\Resources\StoredFunction;
use DreamFactory\Core\SqlDb\Resources\StoredProcedure;
use DreamFactory\Core\SqlDb\Services\SqlDb;
use Illuminate\Support\Arr;

/**
 * Class RedshiftDb
 *
 * @package DreamFactory\Core\Aws\Services
 */
class RedshiftDb extends SqlDb
{
    public static function adaptConfig(array &$config)
    {
        $config['driver'] = 'redshift';
        parent::adaptConfig($config);
    }

    public function getResourceHandlers()
    {
        $handlers = parent::getResourceHandlers();

        $handlers[StoredProcedure::RESOURCE_NAME] = [
            'name'       => StoredProcedure::RESOURCE_NAME,
            'class_name' => StoredProcedure::class,
            'label'      => 'Stored Procedure',
        ];
        $handlers[StoredFunction::RESOURCE_NAME] = [
            'name'       => StoredFunction::RESOURCE_NAME,
            'class_name' => StoredFunction::class,
            'label'      => 'Stored Function',
        ];

        return $handlers;
    }

    protected function updateTableWithConstraints(TableSchema $table, $constraints)
    {
        $serviceId = $this->getServiceId();
        $defaultSchema = $this->getNamingSchema();

        // handle local constraints
        $ts = strtolower($table->schemaName);
        $tn = strtolower($table->resourceName);
        if (isset($constraints[$ts][$tn])) {
            foreach ($constraints[$ts][$tn] as $conName => $constraint) {
                $table->constraints[strtolower($conName)] = $constraint;
                $cn = (array)$constraint['column_name'];
                $type = strtolower(Arr::get($constraint, 'constraint_type', ''));
                switch ($type[0]) {
                    case 'p':
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isPrimaryKey = true;
                                if ((1 === count($cn)) && $column->autoIncrement &&
                                    (DbSimpleTypes::TYPE_INTEGER === $column->type)) {
                                    $column->type = DbSimpleTypes::TYPE_ID;
                                }
                                $table->addColumn($column);
                                $table->addPrimaryKey($colName);
                            }
                        }
                        break;
                    case 'u':
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isUnique = true;
                                $table->addColumn($column);
                            }
                        }
                        break;
                    case 'f':
                        // belongs_to
                        $rts = Arr::get($constraint, 'referenced_table_schema', '');
                        $rtn = Arr::get($constraint, 'referenced_table_name', '');
                        $rcn = (array)Arr::get($constraint, 'referenced_column_name');
                        $name = ($rts == $defaultSchema) ? $rtn : $rts . '.' . $rtn;
                        foreach ($cn as $cndx => $colName) {
                            if ($column = $table->getColumn($colName)) {
                                $column->isForeignKey = true;
                                $column->refTable = $name;
                                $column->refField = Arr::get($rcn, $cndx);
                                if ((1 === count($rcn)) && (DbSimpleTypes::TYPE_INTEGER === $column->type)) {
                                    $column->type = DbSimpleTypes::TYPE_REF;
                                }
                                $table->addColumn($column);
                            }
                        }

                        // Add it to our foreign references as well
                        $relation = new RelationSchema([
                            'type'           => RelationSchema::BELONGS_TO,
                            'field'          => $cn,
                            'ref_service_id' => $serviceId,
                            'ref_table'      => $name,
                            'ref_field'      => $rcn,
                            'ref_on_update'  => Arr::get($constraint, 'update_rule'),
                            'ref_on_delete'  => Arr::get($constraint, 'delete_rule'),
                        ]);

                        $table->addRelation($relation);
                        break;
                }
            }
        }

        foreach ($constraints as $constraintName => $constraint) {
            if (0 !== strncasecmp('f', strtolower(Arr::get($constraint, 'constraint_type', '')), 1)) {
                continue;
            }

            $rts = Arr::get($constraint, 'referenced_table_schema', '');
            $rtn = Arr::get($constraint, 'referenced_table_name');
            if ((0 === strcasecmp($rtn, $table->resourceName)) && (0 === strcasecmp($rts, $table->schemaName))) {
                $ts = Arr::get($constraint, 'table_schema', '');
                $tn = Arr::get($constraint, 'table_name');
                $tsk = strtolower($ts);
                $tnk = strtolower($tn);
                $cn = Arr::get($constraint, 'column_name');
                $rcn = Arr::get($constraint, 'referenced_column_name');
                $name = ($ts == $defaultSchema) ? $tn : $ts . '.' . $tn;
                $type = RelationSchema::HAS_MANY;
                if (isset($constraints[$tsk][$tnk])) {
                    foreach ($constraints[$tsk][$tnk] as $constraintName2 => $constraint2) {
                        $type2 = strtolower(Arr::get($constraint2, 'constraint_type', ''));
                        switch ($type2[0]) {
                            case 'p':
                            case 'u':
                                // if this references a primary or unique constraint on the table then it is HAS_ONE
                                $cn2 = $constraint2['column_name'];
                                if ($cn2 === $cn) {
                                    $type = RelationSchema::HAS_ONE;
                                }
                                break;
                            case 'f':
                                // if other has foreign keys to other tables, we can say these are related as well
                                $rts2 = Arr::get($constraint2, 'referenced_table_schema', '');
                                $rtn2 = Arr::get($constraint2, 'referenced_table_name');
                                if (!((0 === strcasecmp($rts2, $table->schemaName)) &&
                                    (0 === strcasecmp($rtn2, $table->resourceName)))
                                ) {
                                    $name2 = ($rts2 == $defaultSchema) ? $rtn2 : $rts2 . '.' . $rtn2;
                                    $cn2 = Arr::get($constraint2, 'column_name');
                                    $rcn2 = Arr::get($constraint2, 'referenced_column_name');
                                    // not same as parent, i.e. via reference back to self
                                    // not the same key
                                    $relation =
                                        new RelationSchema([
                                            'type'                => RelationSchema::MANY_MANY,
                                            'field'               => $rcn,
                                            'ref_service_id'      => $serviceId,
                                            'ref_table'           => $name2,
                                            'ref_field'           => $rcn2,
                                            'ref_on_update'       => Arr::get($constraint, 'update_rule'),
                                            'ref_on_delete'       => Arr::get($constraint, 'delete_rule'),
                                            'junction_service_id' => $serviceId,
                                            'junction_table'      => $name,
                                            'junction_field'      => $cn,
                                            'junction_ref_field'  => $cn2
                                        ]);

                                    $table->addRelation($relation);
                                }
                                break;
                        }
                    }

                    $relation = new RelationSchema([
                        'type'           => $type,
                        'field'          => $rcn,
                        'ref_service_id' => $serviceId,
                        'ref_table'      => $name,
                        'ref_field'      => $cn,
                        'ref_on_update'  => Arr::get($constraint, 'update_rule'),
                        'ref_on_delete'  => Arr::get($constraint, 'delete_rule'),
                    ]);

                    $table->addRelation($relation);
                }
            }
        }
    }
}