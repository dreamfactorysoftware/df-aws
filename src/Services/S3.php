<?php
/**
 * This file is part of the DreamFactory(tm)
 *
 * DreamFactory(tm) <http://github.com/dreamfactorysoftware/rave>
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

namespace DreamFactory\Core\Aws\Services;

use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Core\Aws\Components\S3FileSystem;
use DreamFactory\Core\Services\RemoteFileService;

/**
 * Class S3
 *
 * @package DreamFactory\Core\Aws\Services
 */
class S3 extends RemoteFileService
{
    /**
     * {@inheritdoc}
     */
    protected function setDriver( $config )
    {
        AwsSvcUtilities::updateCredentials( $config, false );
        $this->driver = new S3FileSystem( $config );
    }

}