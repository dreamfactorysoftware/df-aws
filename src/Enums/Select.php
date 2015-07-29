<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable Select values
 */
class Select extends Enum
{
    const ALL_ATTRIBUTES = 'ALL_ATTRIBUTES';
    const ALL_PROJECTED_ATTRIBUTES = 'ALL_PROJECTED_ATTRIBUTES';
    const SPECIFIC_ATTRIBUTES = 'SPECIFIC_ATTRIBUTES';
    const COUNT = 'COUNT';
}
