<?php

class AwsDbConfigTest extends \DreamFactory\Core\Database\Testing\DbServiceConfigTestCase
{
    protected $types = ['aws_dynamodb', 'aws_redshift_db'];

    public function getDbServiceConfig($name, $type, $maxRecords = null)
    {
        $config = [
            'name' => $name,
            'label' => 'test db service',
            'type' => $type,
            'is_active' => true,
            'config' => [
                'key' => 'fake-key',
                'secret' => 'fake-secret',
                'region' => 'FAKE',
                'host' => 'localhost',
                'database' => 'test-db'
            ]
        ];

        if(!empty($maxRecords)){
            $config['config']['max_records'] = $maxRecords;
        }

        return $config;
    }
}