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

use DreamFactory\Library\Utility\Enums\Verbs;

class FileServiceS3Test extends \Rave\Testing\FileServiceTestCase
{
    protected static $staged = false;

    public function stage()
    {
        parent::stage();

        Artisan::call('migrate', ['--path' => 'vendor/dreamfactory/rave-aws/database/migrations/']);
        Artisan::call('db:seed', ['--class' => 'DreamFactory\\Aws\\Database\\Seeds\\AwsSeeder']);
        if(!$this->serviceExists('s3'))
        {
            \Rave\Models\Service::create(
                [
                    "name"        => "s3",
                    "label"       => "S3 file service",
                    "description" => "S3 file service for unit test",
                    "is_active"   => 1,
                    "type"        => "s3_file",
                    "config"      => [
                        'key' => env('AWS_S3_KEY'),
                        'secret' => env('AWS_S3_SECRET'),
                        'region' => env('AWS_S3_REGION')
                    ]
                ]
            );
        }
    }

    protected function setService()
    {
        $this->service = 's3';
        $this->prefix = $this->prefix.'/'.$this->service;
    }

    /************************************************
     * Testing POST
     ************************************************/

    public function testPOSTContainerWithCheckExist()
    {
        $payload = '{"name":"'.static::CONTAINER_2.'"}';

        $rs = $this->callWithPayload(Verbs::POST, $this->prefix, $payload);
        $this->assertEquals('{"name":"'.static::CONTAINER_2.'","path":"'.static::CONTAINER_2.'"}', $rs->getContent());

        //Check_exist is not currently supported on S3FileSystem class.
        //$rs = $this->_call(Verbs::POST, $this->prefix."?check_exist=true", $payload);
        //$this->assertResponseStatus(400);
        //$this->assertContains("Container 'beta15lam' already exists.", $rs->getContent());
    }

    /************************************************
     * Testing GET
     ************************************************/

    public function testGETContainerIncludeProperties()
    {
        $this->assertEquals(1,1);
        //This feature is not currently supported on S3FileSystem class
        //$rs = $this->call(Verbs::GET, $this->prefix."?include_properties=true");
    }
}
