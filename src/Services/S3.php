<?php
namespace DreamFactory\Core\Aws\Services;

use DreamFactory\Core\Aws\Components\S3FileSystem;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Services\RemoteFileService;

/**
 * Class S3
 *
 * @package DreamFactory\Core\Aws\Services
 */
class S3 extends RemoteFileService
{
    /**
     * {@inheritdoc}
     */
    protected function setDriver($config)
    {
        $this->container = array_get($config, 'container');

        if (empty($this->container)) {
            throw new InternalServerErrorException('S3 file service bucket not specified. Please check configuration for file service - ' .
                $this->name);
        }

        $this->driver = new S3FileSystem($config);
    }
}