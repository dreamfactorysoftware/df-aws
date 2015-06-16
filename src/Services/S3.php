<?php
namespace DreamFactory\Core\Aws\Services;

use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Core\Aws\Components\S3FileSystem;
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
        AwsSvcUtilities::updateCredentials($config, false);
        $this->driver = new S3FileSystem($config);
    }
}