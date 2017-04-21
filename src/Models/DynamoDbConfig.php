<?php
namespace DreamFactory\Core\Aws\Models;

use DreamFactory\Core\Database\Components\SupportsUpsert;

class DynamoDbConfig extends AwsConfig
{
    use SupportsUpsert;
}