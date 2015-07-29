<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable IndexStatus values
 */
class IndexStatus extends Enum
{
    const CREATING = 'CREATING';
    const UPDATING = 'UPDATING';
    const DELETING = 'DELETING';
    const ACTIVE = 'ACTIVE';
}
