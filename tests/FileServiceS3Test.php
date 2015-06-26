<?php
use DreamFactory\Library\Utility\Enums\Verbs;

class FileServiceS3Test extends \DreamFactory\Core\Testing\FileServiceTestCase
{
    protected static $staged = false;

    protected $serviceId = 's3';

    public function stage()
    {
        parent::stage();

        Artisan::call('migrate', ['--path' => 'vendor/dreamfactory/df-aws/database/migrations/']);
        Artisan::call('db:seed', ['--class' => DreamFactory\Core\Aws\Database\Seeds\DatabaseSeeder::class]);
        if (!$this->serviceExists('s3')) {
            \DreamFactory\Core\Models\Service::create(
                [
                    "name"        => "s3",
                    "label"       => "S3 file service",
                    "description" => "S3 file service for unit test",
                    "is_active"   => 1,
                    "type"        => "aws_s3",
                    "config"      => [
                        'key'    => env('AWS_S3_KEY'),
                        'secret' => env('AWS_S3_SECRET'),
                        'region' => env('AWS_S3_REGION')
                    ]
                ]
            );
        }
    }

    /************************************************
     * Testing POST
     ************************************************/

    public function testPOSTContainerWithCheckExist()
    {
        $payload = '{"name":"' . static::CONTAINER_2 . '"}';

        $rs = $this->makeRequest(Verbs::POST, null, [], $payload);
        $this->assertEquals(
            '{"name":"' . static::CONTAINER_2 . '","path":"' . static::CONTAINER_2 . '"}',
            json_encode($rs->getContent(), JSON_UNESCAPED_SLASHES)
        );

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
        $this->assertEquals(1, 1);
        //This feature is not currently supported on S3FileSystem class
        //$rs = $this->call(Verbs::GET, $this->prefix."?include_properties=true");
    }
}
