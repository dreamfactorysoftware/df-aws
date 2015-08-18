<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable ReturnValue values
 */
class ReturnValue extends Enum
{
    const NONE = 'NONE';
    const ALL_OLD = 'ALL_OLD';
    const UPDATED_OLD = 'UPDATED_OLD';
    const ALL_NEW = 'ALL_NEW';
    const UPDATED_NEW = 'UPDATED_NEW';
}
