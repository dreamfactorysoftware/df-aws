<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains all enumerable DynamoDB attribute type values
 */
class Type extends Enum
{
    const S = 'S';
    const N = 'N';
    const B = 'B';

    const SS = 'SS';
    const NS = 'NS';
    const BS = 'BS';

    const STRING = 'S';
    const NUMBER = 'N';
    const BINARY = 'B';

    const STRING_SET = 'SS';
    const NUMBER_SET = 'NS';
    const BINARY_SET = 'BS';
}
