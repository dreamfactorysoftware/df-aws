<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\Ses\SesClient;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use Illuminate\Mail\Transport\SesTransport;
use DreamFactory\Core\Email\Services\BaseService;
use Illuminate\Support\Arr;

class Ses extends BaseService
{
    /**
     * {@inheritdoc}
     */
    protected function setTransport($config)
    {
        $key = Arr::get($config, 'key');
        $secret = Arr::get($config, 'secret');
        $region = Arr::get($config, 'region', 'us-east-1');

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
        if (empty($key) || empty($secret) || empty($region)) {
            throw new InternalServerErrorException('Missing one or more configuration for SES service.');
        }

        $config = [
            'key'     => $key,
            'secret'  => $secret,
            'region'  => $region,
            'version' => 'latest',
            'service' => 'email'
        ];

        if ($config['key'] && $config['secret']) {
            $config['credentials'] = Arr::only($config, ['key', 'secret']);
        }

        return new SesTransport(new SesClient($config));
    }
}