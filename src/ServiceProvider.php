<?php
namespace DreamFactory\Core\Aws;

use DreamFactory\Core\Aws\Components\AwsS3Config;
use DreamFactory\Core\Aws\Models\AwsConfig;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Core\Aws\Services\S3;
use DreamFactory\Core\Aws\Services\Ses;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Components\ServiceDocBuilder;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    use ServiceDocBuilder;

    public function register()
    {
        // Add our service types.
        $this->app->resolving('df.service', function (ServiceManager $df) {
            $df->addType(
                new ServiceType([
                    'name'            => 'aws_s3',
                    'label'           => 'AWS S3',
                    'description'     => 'File storage service supporting the AWS S3 file system.',
                    'group'           => ServiceTypeGroups::FILE,
                    'config_handler'  => AwsS3Config::class,
                    'default_api_doc' => function ($service) {
                        return $this->buildServiceDoc($service->id, S3::getApiDocInfo($service));
                    },
                    'factory'         => function ($config) {
                        return new S3($config);
                    }
                ])
            );
            $df->addType(
                new ServiceType([
                    'name'            => 'aws_dynamodb',
                    'label'           => 'AWS DynamoDB',
                    'description'     => 'A database service supporting the AWS DynamoDB system.',
                    'group'           => ServiceTypeGroups::DATABASE,
                    'config_handler'  => AwsConfig::class,
                    'default_api_doc' => function ($service) {
                        return $this->buildServiceDoc($service->id, DynamoDb::getApiDocInfo($service));
                    },
                    'factory'         => function ($config) {
                        return new DynamoDb($config);
                    }
                ])
            );
            $df->addType(
                new ServiceType([
                    'name'            => 'aws_sns',
                    'label'           => 'AWS SNS',
                    'description'     => 'Push notification service supporting the AWS SNS system.',
                    'group'           => ServiceTypeGroups::NOTIFICATION,
                    'config_handler'  => AwsConfig::class,
                    'default_api_doc' => function ($service) {
                        return $this->buildServiceDoc($service->id, Sns::getApiDocInfo($service));
                    },
                    'factory'         => function ($config) {
                        return new Sns($config);
                    }
                ])
            );
            $df->addType(
                new ServiceType([
                    'name'            => 'aws_ses',
                    'label'           => 'AWS SES',
                    'description'     => 'Email service supporting the AWS SES system.',
                    'group'           => ServiceTypeGroups::EMAIL,
                    'config_handler'  => AwsConfig::class,
                    'default_api_doc' => function ($service) {
                        return $this->buildServiceDoc($service->id, Ses::getApiDocInfo($service));
                    },
                    'factory'         => function ($config) {
                        return new Ses($config);
                    }
                ])
            );
        });
    }
}
