<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\Ses\SesClient;
use Illuminate\Mail\Transport\SesTransport;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Services\Email\BaseService;

class Ses extends BaseService
{
    /**
     * {@inheritdoc}
     */
    protected function setTransport($config)
    {
        $key = ArrayUtils::get($config, 'key');
        $secret = ArrayUtils::get($config, 'secret');
        $region = ArrayUtils::get($config, 'region', 'us-east-1');

        $this->transport = static::getTransport($key, $secret, $region);
    }

    public static function getTransport($key, $secret, $region)
    {
        $sesClient = SesClient::factory([
            'key'    => $key,
            'secret' => $secret,
            'region' => $region
        ]);

        return new SesTransport($sesClient);
    }
}