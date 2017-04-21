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

    protected $fillable = ['service_id', 'region', 'key', 'secret'];

    protected $rules = [
        'key'    => 'required',
        'secret' => 'required',
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
                    ['label' => 'US WEST (N California)', 'name' => 'us-west-1'],
                    ['label' => 'US WEST (Oregon)', 'name' => 'us-west-2'],
                    ['label' => 'EU (Ireland)', 'name' => 'eu-west-1'],
                    ['label' => 'EU (Frankfurt)', 'name' => 'eu-central-1'],
                    ['label' => 'Asia Pacific (Singapore)', 'name' => 'ap-southeast-1'],
                    ['label' => 'Asia Pacific (Sydney)', 'name' => 'ap-southeast-2'],
                    ['label' => 'Asia Pacific (Tokyo)', 'name' => 'ap-northeast-1'],
                    ['label' => 'South America (Sao Paulo)', 'name' => 'sa-east-1']
                ];
                $schema['description'] = 'Select the region to be accessed by this service connection.';
                break;
            case 'key':
                $schema['label'] = 'Access Key ID';
                $schema['description'] = 'An AWS account root or IAM access key.';
                break;
            case 'secret':
                $schema['label'] = 'Secret Access Key';
                $schema['description'] = 'An AWS account root or IAM secret key.';
                break;
        }
    }
}