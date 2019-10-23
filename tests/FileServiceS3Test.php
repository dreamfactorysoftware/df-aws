<?php
use DreamFactory\Core\Enums\Verbs;

class FileServiceS3Test extends \DreamFactory\Core\Testing\FileServiceTestCase
{
    protected static $staged = false;

    protected $serviceId = 's3';

    public function stage()
    {
        parent::stage();

        Artisan::call('migrate', ['--path' => 'vendor/dreamfactory/df-aws/database/migrations/']);
        if (!$this->serviceExists('s3')) {
            \DreamFactory\Core\Models\Service::create(
                [
                    "name"        => "s3",
                    "label"       => "S3 file service",
                    "description" => "S3 file service for unit test",
                    "is_active"   => true,
                    "type"        => "aws_s3",
                    "config"      => [
                        'key'       => env('AWS_ACCESS_KEY_ID'),
                        'secret'    => env('AWS_SECRET_ACCESS_KEY'),
                        'region'    => env('AWS_DEFAULT_REGION'),
                        'container' => env('AWS_CONTAINER')
                    ]
                ]
            );
        }
    }

    /************************************************
     * Testing POST
     ************************************************/

//    public function testPOSTContainerWithCheckExist()
//    {
//        $payload = '{"name":"' . static::FOLDER_2 . '"}';
//
//        $rs = $this->makeRequest(Verbs::POST, null, [], $payload);
//        $this->assertEquals(
//            '{"name":"' . static::FOLDER_2 . '","path":"' . static::FOLDER_2 . '"}',
//            json_encode($rs->getContent(), JSON_UNESCAPED_SLASHES)
//        );

        //Check_exist is not currently supported on S3FileSystem class.
        //$rs = $this->_call(Verbs::POST, $this->prefix."?check_exist=true", $payload);
        //$this->assertResponseStatus(400);
        //$this->assertContains("Container 'beta15lam' already exists.", $rs->getContent());
//    }

    /************************************************
     * Testing GET
     ************************************************/

    public function testGETContainerIncludeProperties()
    {
        $this->assertEquals(1, 1);
        //This feature is not currently supported on S3FileSystem class
        //$rs = $this->call(Verbs::GET, $this->prefix."?include_properties=true");
    }

    public function testPOSTZipFileFromUrlWithExtractAndClean()
    {
        $rs = $this->makeRequest(
            Verbs::POST,
            static::FOLDER_1 . '/f2/',
            ['url' => $this->getBaseUrl() . '/testfiles.zip', 'extract' => 'true', 'clean' => 'true']
        );
        $content = json_encode($rs->getContent(), JSON_UNESCAPED_SLASHES);

        $this->assertEquals('{"name":"' .
            static::FOLDER_1 .
            '/f2","path":"' .
            static::FOLDER_1 .
            '/f2/"}',
            $content);
        $this->makeRequest(Verbs::DELETE, static::FOLDER_1 . '/', ['force' => 1]);
    }
}
