<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable ComparisonOperator values
 */
class ComparisonOperator extends Enum
{
    const EQ = 'EQ';
    const NE = 'NE';
    const IN = 'IN';
    const LE = 'LE';
    const LT = 'LT';
    const GE = 'GE';
    const GT = 'GT';
    const BETWEEN = 'BETWEEN';
    const NOT_NULL = 'NOT_NULL';
    const NULL = 'NULL';
    const CONTAINS = 'CONTAINS';
    const NOT_CONTAINS = 'NOT_CONTAINS';
    const BEGINS_WITH = 'BEGINS_WITH';
}
