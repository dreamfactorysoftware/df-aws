<?php
namespace DreamFactory\Core\Aws\Models;

use DreamFactory\Core\Database\Components\SupportsUpsertAndCache;

class DynamoDbConfig extends AwsConfig
{
    use SupportsUpsertAndCache;
}