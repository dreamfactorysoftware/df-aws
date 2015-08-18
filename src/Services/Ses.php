<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\Ses\SesClient;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
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

    /**
     * @param $key
     * @param $secret
     * @param $region
     *
     * @return \Illuminate\Mail\Transport\SesTransport
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public static function getTransport($key, $secret, $region)
    {
        if(empty($key) || empty($secret) || empty($region)){
            throw new InternalServerErrorException('Missing one or more configuration for SES service.');
        }
        $sesClient = SesClient::factory([
            'key'    => $key,
            'secret' => $secret,
            'region' => $region
        ]);

        return new SesTransport($sesClient);
    }
}