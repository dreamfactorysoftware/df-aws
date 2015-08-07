<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable ProjectionType values
 */
class ProjectionType extends Enum
{
    const ALL = 'ALL';
    const KEYS_ONLY = 'KEYS_ONLY';
    const INCLUDED = 'INCLUDE';
}
