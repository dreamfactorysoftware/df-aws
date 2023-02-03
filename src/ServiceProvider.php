<?php
namespace DreamFactory\Core\Aws;

use DreamFactory\Core\Aws\Database\Connectors\RedshiftConnector;
use DreamFactory\Core\Aws\Database\RedshiftConnection;
use DreamFactory\Core\Aws\Database\Schema\RedshiftSchema;
use DreamFactory\Core\Aws\Models\AwsConfig;
use DreamFactory\Core\Aws\Models\S3Config;
use DreamFactory\Core\Aws\Models\DynamoDbConfig;
use DreamFactory\Core\Aws\Models\RedshiftDbConfig;
use DreamFactory\Core\Aws\Services\DynamoDb;
use DreamFactory\Core\Aws\Services\RedshiftDb;
use DreamFactory\Core\Aws\Services\S3;
use DreamFactory\Core\Aws\Services\Ses;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Components\DbSchemaExtensions;
use DreamFactory\Core\Enums\ServiceTypeGroups;
use DreamFactory\Core\Services\ServiceManager;
use DreamFactory\Core\Services\ServiceType;
use Illuminate\Database\DatabaseManager;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
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
                    'config_handler'  => S3Config::class,
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
                    'config_handler'  => DynamoDbConfig::class,
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
                    'factory'         => function ($config) {
                        return new Ses($config);
                    }
                ])
            );
            $df->addType(
                new ServiceType([
                    'name'            => 'aws_redshift_db',
                    'label'           => 'AWS Redshift DB',
                    'description'     => 'A database service supporting AWS Redshift.',
                    'group'           => ServiceTypeGroups::DATABASE,
                    'config_handler'  => RedshiftDbConfig::class,
                    'factory'         => function ($config) {
                        return new RedshiftDb($config);
                    }
                ])
            );
        });

        // Add our database drivers.
        $this->app->resolving('db', function (DatabaseManager $db) {
            $db->extend('redshift', function ($config) {
                $connector = new RedshiftConnector();
                $connection = $connector->connect($config);

                return new RedshiftConnection($connection, $config["database"], $config["prefix"], $config);
            });
        });

        // Add our database extensions.
        $this->app->resolving('df.db.schema', function (DbSchemaExtensions $db) {
            $db->extend('redshift', function ($connection) {
                return new RedshiftSchema($connection);
            });
        });
    }

    public function boot()
    {
        // add migrations
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
