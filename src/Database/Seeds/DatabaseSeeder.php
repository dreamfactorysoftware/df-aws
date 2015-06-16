<?php
namespace DreamFactory\Core\Aws\Database\Seeds;

use DreamFactory\Core\Database\Seeds\BaseModelSeeder;

class DatabaseSeeder extends BaseModelSeeder
{
    protected $modelClass = 'DreamFactory\\Core\\Models\\ServiceType';

    protected $records = [
        [
            'name'           => 'aws_s3',
            'class_name'     => "DreamFactory\\Core\\Aws\\Services\\S3",
            'config_handler' => "DreamFactory\\Core\\Aws\\Models\\AwsConfig",
            'label'          => 'AWS S3 file service',
            'description'    => 'File service supporting the AWS S3 file system.',
            'group'          => 'files',
            'singleton'      => 1
        ],
        [
            'name'           => 'aws_dynamodb',
            'class_name'     => "DreamFactory\\Core\\Aws\\Services\\DynamoDb",
            'config_handler' => "DreamFactory\\Core\\Aws\\Models\\AwsConfig",
            'label'          => 'AWS DynamoDb service',
            'description'    => 'NoSQL database service supporting the AWS DynamoDb system.',
            'group'          => 'database, nosql',
            'singleton'      => 1
        ],
        [
            'name'           => 'aws_simpledb',
            'class_name'     => "DreamFactory\\Core\\Aws\\Services\\SimpleDb",
            'config_handler' => "DreamFactory\\Core\\Aws\\Models\\AwsConfig",
            'label'          => 'AWS SimpleDb service',
            'description'    => 'NoSQL database service supporting the AWS SimpleDb system.',
            'group'          => 'database, nosql',
            'singleton'      => 1
        ],
        [
            'name'           => 'aws_sns',
            'class_name'     => "DreamFactory\\Core\\Aws\\Services\\Sns",
            'config_handler' => "DreamFactory\\Core\\Aws\\Models\\AwsConfig",
            'label'          => 'AWS SNS service',
            'description'    => 'Push notification service supporting the AWS SNS system.',
            'group'          => 'push',
            'singleton'      => 1
        ],
        [
            'name'           => 'aws_ses',
            'class_name'     => "DreamFactory\\Core\\Aws\\Services\\Ses",
            'config_handler' => "DreamFactory\\Core\\Models\\EmailServiceConfig",
            'label'          => 'AWS SES service',
            'description'    => 'Email service supporting the AWS SES system.',
            'group'          => 'emails',
            'singleton'      => 1
        ]
    ];
}