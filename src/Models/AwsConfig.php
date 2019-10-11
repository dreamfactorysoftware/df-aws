<?php

namespace DreamFactory\Core\Aws\Models;

use DreamFactory\Core\Models\BaseServiceConfigModel;

/**
 * Class AwsConfig
 *
 * @package DreamFactory\Core\Aws\Models
 */
class AwsConfig extends BaseServiceConfigModel
{
    protected $table = 'aws_config';

    protected $encrypted = ['key', 'secret'];

    protected $protected = ['secret'];

    protected $fillable = ['service_id', 'region', 'key', 'secret', 'proxy'];

    protected $rules = [
        'region' => 'required'
    ];

    /**
     * @param array $schema
     */
    protected static function prepareConfigSchemaField(array &$schema)
    {
        parent::prepareConfigSchemaField($schema);

        switch ($schema['name']) {
            case 'region':
                $schema['type'] = 'picklist';
                $schema['values'] = [
                    ['label' => 'US EAST (N Virgina)', 'name' => 'us-east-1'],
                    ['label' => 'US EAST (Ohio)', 'name' => 'us-east-2'],
                    ['label' => 'US WEST (N California)', 'name' => 'us-west-1'],
                    ['label' => 'US WEST (Oregon)', 'name' => 'us-west-2'],
                    ['label' => 'Canada (Central)', 'name' => 'ca-central-1'],
                    ['label' => 'EU (Ireland)', 'name' => 'eu-west-1'],
                    ['label' => 'EU (London)', 'name' => 'eu-west-2'],
                    ['label' => 'EU (Frankfurt)', 'name' => 'eu-central-1'],
                    ['label' => 'Asia Pacific (Mumbai)', 'name' => 'ap-south-1'],
                    ['label' => 'Asia Pacific (Singapore)', 'name' => 'ap-southeast-1'],
                    ['label' => 'Asia Pacific (Sydney)', 'name' => 'ap-southeast-2'],
                    ['label' => 'Asia Pacific (Tokyo)', 'name' => 'ap-northeast-1'],
                    ['label' => 'Asia Pacific (Seoul)', 'name' => 'ap-northeast-2'],
                    ['label' => 'South America (Sao Paulo)', 'name' => 'sa-east-1']
                ];
                $schema['description'] = 'Select the region to be accessed by this service connection.';
                break;
            case 'key':
                $schema['label'] = 'Access Key ID';
                $schema['description'] = 'An AWS account root or IAM access key. ' .
                    'If you do not explicitly provide credentials here and no environment variable ' .
                    '(AWS_ACCESS_KEY_ID) credentials are available, DreamFactory attempts to retrieve ' .
                    'instance profile credentials from an Amazon EC2 instance metadata server. ' .
                    'These credentials are available only when running DreamFactory on Amazon EC2 ' .
                    'instances that have been configured with an IAM role.';
                break;
            case 'secret':
                $schema['label'] = 'Secret Access Key';
                $schema['description'] = 'An AWS account root or IAM secret key. ' .
                    'If you do not explicitly provide credentials here and no environment variable ' .
                    '(AWS_SECRET_ACCESS_KEY) credentials are available, DreamFactory attempts to retrieve ' .
                    'instance profile credentials from an Amazon EC2 instance metadata server. ' .
                    'These credentials are available only when running DreamFactory on Amazon EC2 ' .
                    'instances that have been configured with an IAM role.';
                break;
            case 'proxy':
                $schema['label'] = 'Proxy';
                $schema['description'] = 'You can connect to an AWS service through a proxy by using the ' .
                    'proxy option. Provide a string value to connect to a proxy for all types of URIs. ' .
                    'The proxy string value can contain a scheme, user name, and password. For example, ' .
                    '"http://username:password@192.168.16.1:10".';
                break;
        }
    }
}