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

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Rave\Aws\Services\Sns;
use DreamFactory\Rave\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Rave\Exceptions\BadRequestException;
use DreamFactory\Rave\Exceptions\InternalServerErrorException;
use DreamFactory\Rave\Exceptions\NotFoundException;
use DreamFactory\Rave\Exceptions\RestException;
use DreamFactory\Rave\Contracts\ServiceResponseInterface;

/**
 * Class SnsApplication
 *
 * @package DreamFactory\Rave\Aws\Resources
 */
class SnsApplication extends BaseSnsResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with application
     */
    const RESOURCE_NAME = 'app';

    //*************************************************************************
    //	Members
    //*************************************************************************

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws null
     */
    protected function _getApplicationsAsArray()
    {
        $_out = array();
        $_token = null;
        try
        {
            do
            {
                $_result = $this->service->getConnection()->listPlatformApplications(
                    array(
                        'NextToken' => $_token
                    )
                );
                $_topics = $_result['PlatformApplications'];
                $_token = $_result['NextToken'];

                if ( !empty( $_topics ) )
                {
                    $_out = array_merge( $_out, $_topics );
                }
            }
            while ( $_token );
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to retrieve applications.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return $_out;
    }

    /**
     * Apply the commonly used REST path members to the class.
     *
     * @param string $resourcePath
     *
     * @return $this
     */
    protected function setResourceMembers( $resourcePath = null )
    {
        parent::setResourceMembers( $resourcePath );

        $this->resource = ArrayUtils::get( $this->resourceArray, 0 );

        $_pos = 1;
        $_more = ArrayUtils::get( $this->resourceArray, $_pos );

        if ( !empty( $_more ) )
        {
            if( SnsEndpoint::RESOURCE_NAME !== $_more )
            {
                do
                {
                    $this->resource .= '/' . $_more;
                    $_pos++;
                    $_more = ArrayUtils::get( $this->resourceArray, $_pos );
                }
                while ( !empty( $_more ) && ( SnsEndpoint::RESOURCE_NAME !== $_more ) );
            }
        }

        return $this;
    }

    /**
     * @param mixed $include_properties Use boolean, comma-delimited string, or array of properties
     *
     * @return ServiceResponseInterface
     */
    public function listResources( $include_properties = null )
    {
        $_resources = array();
        $_result = $this->_getApplicationsAsArray();
        foreach ( $_result as $_app )
        {
            switch ( $include_properties )
            {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $_resources[] = $this->service->stripArnPrefix( ArrayUtils::get( $_app, 'PlatformApplicationArn' ) );
                    break;
                case Sns::FORMAT_ARN:
                    $_resources[] = ArrayUtils::get( $_app, 'PlatformApplicationArn' );
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $_app['Application'] = $this->service->stripArnPrefix( ArrayUtils::get( $_app, 'PlatformApplicationArn' ) );
                    $_resources[] = $_app;
                    break;
            }
        }

        return $_resources;
    }

    protected function handleGET()
    {
        if ( empty( $this->resource ) )
        {
            return parent::handleGET();
        }
        else
        {
            return $this->retrieveApplication( $this->resource );
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in application post request.' );
        }

        if ( empty( $this->resource ) )
        {
            return $this->createApplication( $payload );
        }
        else
        {
            return false;
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in application update request.' );
        }

        if ( !empty( $this->resource ) )
        {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateApplication( $payload );
    }

    protected function handlePATCH()
    {
        return $this->handlePUT();
    }

    protected function handleDELETE()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $this->resource ) )
        {
            if ( empty( $payload ) )
            {
                throw new BadRequestException( 'No data in application delete request.' );
            }

            $this->deleteApplication( $payload );
        }
        else
        {
            $this->deleteApplication( $this->resource );
        }

        return array( 'success' => true );
    }

    /**
     * @param $resource
     *
     * @return array
     * @throws InternalServerErrorException
     * @throws NotFoundException
     */
    public function retrieveApplication( $resource )
    {
        $_request = array( 'PlatformApplicationArn' => $this->service->addArnPrefix( $resource ) );

        try
        {
            if ( null !== $_result = $this->service->getConnection()->getPlatformApplicationAttributes( $_request ) )
            {
                $_attributes = ArrayUtils::get( $_result->toArray(), 'Attributes' );

                return array(
                    'Application'            => $this->service->stripArnPrefix( $resource ),
                    'PlatformApplicationArn' => $this->service->addArnPrefix( $resource ),
                    'Attributes'             => $_attributes
                );
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to retrieve properties for '$resource'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return array();
    }

    public function createApplication( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Name' );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Create application request contains no 'Name' field." );
            }
        }
        else
        {
            throw new BadRequestException( "Create application request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->createPlatformApplication( $request ) )
            {
                $_arn = ArrayUtils::get( $_result->toArray(), 'PlatformApplicationArn', '' );

                return array( 'Application' => $this->service->stripArnPrefix( $_arn ), 'PlatformApplicationArn' => $_arn );
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to create application '{$request['Name']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return array();
    }

    public function updateApplication( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Application', ArrayUtils::get( $request, 'PlatformApplicationArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Update application request contains no 'Application' field." );
            }

            $request['PlatformApplicationArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            throw new BadRequestException( "Update application request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->setPlatformApplicationAttributes( $request ) )
            {
                return array( 'success' => true );
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException(
                "Failed to update application '{$request['PlatformApplicationArn']}'.\n{$_ex->getMessage()}", $_ex->getCode()
            );
        }

        return array();
    }

    public function deleteApplication( $request )
    {
        $_data = array();
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Application', ArrayUtils::get( $request, 'PlatformApplicationArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Delete application request contains no 'Application' field." );
            }

            $_data['PlatformApplicationArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            $_data['PlatformApplicationArn'] = $this->service->addArnPrefix( $request );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->deletePlatformApplication( $_data ) )
            {
                return array( 'success' => true );
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException(
                "Failed to delete application '{$_data['PlatformApplicationArn']}'.\n{$_ex->getMessage()}", $_ex->getCode()
            );
        }

        return array();
    }
}