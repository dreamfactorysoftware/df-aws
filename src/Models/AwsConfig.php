<?php
namespace DreamFactory\Core\Aws\Models;

use DreamFactory\Core\Contracts\ServiceConfigHandlerInterface;
use DreamFactory\Core\Models\BaseServiceConfigModel;

/**
 * Class AwsConfig
 *
 * @package DreamFactory\Core\Aws\Models
 */
class AwsConfig extends BaseServiceConfigModel implements ServiceConfigHandlerInterface
{
    protected $table = 'aws_config';

    protected $encrypted = ['key', 'secret'];

    protected $fillable = ['service_id', 'region', 'key', 'secret'];
}