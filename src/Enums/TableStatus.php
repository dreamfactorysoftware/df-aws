<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Contains enumerable TableStatus values
 */
class TableStatus extends Enum
{
    const CREATING = 'CREATING';
    const UPDATING = 'UPDATING';
    const DELETING = 'DELETING';
    const ACTIVE = 'ACTIVE';
}
