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

namespace DreamFactory\Rave\Aws\Resources;

use DreamFactory\Rave\Resources\BaseRestResource;
use DreamFactory\Rave\Aws\Services\Sns;

class BaseSnsResource extends BaseRestResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var null|Sns
     */
    protected $service = null;

    /**
     * @var null|string
     */
    protected $parentResource = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @param Sns $service
     * @param array $settings
     */
    public function __construct( $service = null, $settings = array() )
    {
        parent::__construct( $settings );

        $this->service = $service;
    }

    /**
     * @param Sns|null $service
     */
    public function setService( $service )
    {
        $this->service = $service;
    }

    /**
     * @param null|string $parentResource
     *
     * @return $this
     */
    public function setParentResource( $parentResource )
    {
        if ( !empty( $parentResource ) )
        {
            $parentResource = $this->service->addArnPrefix( $parentResource );
        }
        $this->parentResource = $parentResource;

        return $this;
    }
}