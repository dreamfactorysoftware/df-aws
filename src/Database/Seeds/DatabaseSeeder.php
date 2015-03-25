<?php
/**
 * This file is part of the DreamFactory Rave(tm)
 *
 * DreamFactory Rave(tm) <http://github.com/dreamfactorysoftware/rave>
 * Copyright 2012-2014 DreamFactory Software, Inc. <support@dreamfactory.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace DreamFactory\Rave\Aws\Database\Seeds;

use Illuminate\Database\Seeder;
use DreamFactory\Rave\Models\ServiceType;

/**
 * Class DatabaseSeeder
 *
 * @package DreamFactory\Rave\Aws\Database\Seeds
 */
class DatabaseSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        if ( !ServiceType::whereName( 'aws_s3' )->count() )
        {
            // Add the service type
            ServiceType::create(
                [
                    'name'           => 'aws_s3',
                    'class_name'     => "DreamFactory\\Rave\\Aws\\Services\\S3",
                    'config_handler' => "DreamFactory\\Rave\\Aws\\Models\\AwsConfig",
                    'label'          => 'AWS S3 file service',
                    'description'    => 'File service supporting the AWS S3 file system.',
                    'group'          => 'files',
                    'singleton'      => 1
                ]
            );
            $this->command->info( 'AWS S3 file service type seeded!' );
        }

        if ( !ServiceType::whereName( 'aws_dynamodb' )->count() )
        {
            // Add the service type
            ServiceType::create(
                [
                    'name'           => 'aws_dynamodb',
                    'class_name'     => "DreamFactory\\Rave\\Aws\\Services\\DynamoDb",
                    'config_handler' => "DreamFactory\\Rave\\Aws\\Models\\AwsConfig",
                    'label'          => 'AWS DynamoDb service',
                    'description'    => 'NoSQL database service supporting the AWS DynamoDb system.',
                    'group'          => 'database, nosql',
                    'singleton'      => 1
                ]
            );
            $this->command->info( 'AWS DynamoDb database service type seeded!' );
        }

        if ( !ServiceType::whereName( 'aws_simpledb' )->count() )
        {
            // Add the service type
            ServiceType::create(
                [
                    'name'           => 'aws_simpledb',
                    'class_name'     => "DreamFactory\\Rave\\Aws\\Services\\SimpleDb",
                    'config_handler' => "DreamFactory\\Rave\\Aws\\Models\\AwsConfig",
                    'label'          => 'AWS SimpleDb service',
                    'description'    => 'NoSQL database service supporting the AWS SimpleDb system.',
                    'group'          => 'database, nosql',
                    'singleton'      => 1
                ]
            );
            $this->command->info( 'AWS SimpleDb database service type seeded!' );
        }

        if ( !ServiceType::whereName( 'aws_sns' )->count() )
        {
            // Add the service type
            ServiceType::create(
                [
                    'name'           => 'aws_sns',
                    'class_name'     => "DreamFactory\\Rave\\Aws\\Services\\Sns",
                    'config_handler' => "DreamFactory\\Rave\\Aws\\Models\\AwsConfig",
                    'label'          => 'AWS SNS service',
                    'description'    => 'Push notification service supporting the AWS SNS system.',
                    'group'          => 'push',
                    'singleton'      => 1
                ]
            );
            $this->command->info( 'AWS SNS push service type seeded!' );
        }
    }

}