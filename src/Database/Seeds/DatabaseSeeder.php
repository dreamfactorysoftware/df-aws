<?php
namespace DreamFactory\Core\Aws\Database\Seeds;

use DreamFactory\Core\Aws\Components\AwsS3Config;
use DreamFactory\Core\Aws\Models\AwsConfig;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Core\Aws\Services\S3;
use DreamFactory\Core\Aws\Services\Ses;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Database\Seeds\BaseModelSeeder;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Models\ServiceType;

class DatabaseSeeder extends BaseModelSeeder
{
    protected $modelClass = ServiceType::class;

    protected $records = [
        [
            'name'           => 'aws_s3',
            'class_name'     => S3::class,
            'config_handler' => AwsS3Config::class,
            'label'          => 'AWS S3',
            'description'    => 'File storage service supporting the AWS S3 file system.',
            'group'          => ServiceTypeGroups::FILE,
            'singleton'      => false
        ],
        [
            'name'           => 'aws_dynamodb',
            'class_name'     => DynamoDb::class,
            'config_handler' => AwsConfig::class,
            'label'          => 'AWS DynamoDB',
            'description'    => 'A database service supporting the AWS DynamoDB system.',
            'group'          => ServiceTypeGroups::DATABASE,
            'singleton'      => false
        ],
        [
            'name'           => 'aws_sns',
            'class_name'     => Sns::class,
            'config_handler' => AwsConfig::class,
            'label'          => 'AWS SNS',
            'description'    => 'Push notification service supporting the AWS SNS system.',
            'group'          => ServiceTypeGroups::NOTIFICATION,
            'singleton'      => false
        ],
        [
            'name'           => 'aws_ses',
            'class_name'     => Ses::class,
            'config_handler' => AwsConfig::class,
            'label'          => 'AWS SES',
            'description'    => 'Email service supporting the AWS SES system.',
            'group'          => ServiceTypeGroups::EMAIL,
            'singleton'      => false
        ]
    ];
}