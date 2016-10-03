<?php

namespace DreamFactory\Core\Aws\Services;

use DreamFactory\Core\SqlDb\Services\SqlDb;

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
}