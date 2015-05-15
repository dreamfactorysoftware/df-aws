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
 * Class SnsEndpoint
 *
 * @package DreamFactory\Rave\Aws\Resources
 */
class SnsEndpoint extends BaseSnsResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with subscription
     */
    const RESOURCE_NAME = 'endpoint';

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
    protected function _getEndpointsAsArray( $application )
    {
        if ( empty( $application ) )
        {
            throw new BadRequestException( 'Platform application name required for retrieving endpoints.' );
        }

        $application = $this->service->addArnPrefix( $application );
        $_out = [];
        $_token = null;
        try
        {
            do
            {
                $_result = $this->service->getConnection()->listEndpointsByPlatformApplication(
                    [
                        'PlatformApplicationArn' => $application,
                        'NextToken'              => $_token
                    ]
                );
                $_topics = $_result['Endpoints'];
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

            throw new InternalServerErrorException( "Failed to retrieve endpoints.\n{$_ex->getMessage()}", $_ex->getCode() );
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
            //  This will be the full resource path
            do
            {
                $this->resource .= '/' . $_more;
                $_pos++;
                $_more = ArrayUtils::get( $this->resourceArray, $_pos );
            }
            while ( !empty( $_more ) );
        }

        return $this;
    }

    /**
     * @param mixed $fields Use boolean, comma-delimited string, or array of properties
     *
     * @return ServiceResponseInterface
     * @throws BadRequestException|InternalServerErrorException|NotFoundException
     */
    public function listResources( $fields = null )
    {
        $_resources = [];
        if ( empty( $this->parentResource ) )
        {
            $applications = [ ];
            try
            {
                $_out = [];
                $_token = null;
                do
                {
                    $_result = $this->service->getConnection()->listPlatformApplications(
                        [
                            'NextToken' => $_token
                        ]
                    );
                    $_topics = $_result['PlatformApplications'];
                    $_token = $_result['NextToken'];

                    if ( !empty( $_topics ) )
                    {
                        $_out = array_merge( $_out, $_topics );
                    }
                }
                while ( $_token );

                foreach ( $_out as $_app )
                {
                    $applications[] = ArrayUtils::get( $_app, 'PlatformApplicationArn' );
                }
            }
            catch ( \Exception $_ex )
            {
                if ( null !== $_newEx = Sns::translateException( $_ex ) )
                {
                    throw $_newEx;
                }

                throw new InternalServerErrorException( "Failed to retrieve applications.\n{$_ex->getMessage()}", $_ex->getCode() );
            }
        }
        else
        {
            $applications = [ $this->parentResource ];
        }

        foreach ( $applications as $application )
        {
            $_result = $this->_getEndpointsAsArray( $application );
            foreach ( $_result as $_end )
            {
                switch ( $fields )
                {
                    case false:
                    case Sns::FORMAT_SIMPLE:
                        $_resources[] = $this->service->stripArnPrefix( ArrayUtils::get( $_end, 'EndpointArn' ) );
                        break;
                    case Sns::FORMAT_ARN:
                        $_resources[] = ArrayUtils::get( $_end, 'EndpointArn' );
                        break;
                    case true:
                    case Sns::FORMAT_FULL:
                    default:
                        $_end['Endpoint'] = $this->service->stripArnPrefix( ArrayUtils::get( $_end, 'EndpointArn' ) );
                        $_resources[] = $_end;
                        break;
                }
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
            return $this->retrieveEndpoint( $this->resource );
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in endpoint post request.' );
        }

        if ( empty( $this->resource ) )
        {
            return $this->createEndpoint( $payload );
        }
        else
        {
            return $this->service->publish( $payload, SnsEndpoint::RESOURCE_NAME, $this->resource );
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in endpoint update request.' );
        }

        if ( !empty( $this->resource ) )
        {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateEndpoint( $payload );
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
                throw new BadRequestException( 'No data in endpoint delete request.' );
            }

            $this->deleteEndpoint( $payload );
        }
        else
        {
            $this->deleteEndpoint( $this->resource );
        }

        return [ 'success' => true ];
    }

    /**
     * @param $resource
     *
     * @return array
     * @throws InternalServerErrorException
     * @throws NotFoundException
     */
    public function retrieveEndpoint( $resource )
    {
        $_request = [ 'EndpointArn' => $this->service->addArnPrefix( $resource ) ];

        try
        {
            if ( null !== $_result = $this->service->getConnection()->getEndpointAttributes( $_request ) )
            {
                $_attributes = ArrayUtils::get( $_result->toArray(), 'Attributes' );

                return [
                    'Endpoint'    => $this->service->stripArnPrefix( $resource ),
                    'EndpointArn' => $this->service->addArnPrefix( $resource ),
                    'Attributes'  => $_attributes
                ];
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

        return [];
    }

    public function createEndpoint( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Application', ArrayUtils::get( $request, 'PlatformApplicationArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Create endpoint request contains no 'Application' field." );
            }
            $request['PlatformApplicationArn'] = $this->service->addArnPrefix( $_name );
            $_name = ArrayUtils::get( $request, 'Token' );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Create endpoint request contains no 'Token' field." );
            }
        }
        else
        {
            throw new BadRequestException( "Create endpoint request contains fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->createPlatformEndpoint( $request ) )
            {
                $_arn = ArrayUtils::get( $_result->toArray(), 'EndpointArn', '' );

                return [ 'Endpoint' => $this->service->stripArnPrefix( $_arn ), 'EndpointArn' => $_arn ];
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException(
                "Failed to create endpoint for '{$request['PlatformApplicationArn']}'.\n{$_ex->getMessage()}", $_ex->getCode()
            );
        }

        return [];
    }

    public function updateEndpoint( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Endpoint', ArrayUtils::get( $request, 'EndpointArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Update endpoint request contains no 'Endpoint' field." );
            }

            $request['EndpointArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            throw new BadRequestException( "Update endpoint request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->setEndpointAttributes( $request ) )
            {
                return [ 'success' => true ];
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to update endpoint '{$request['EndpointArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }

    public function deleteEndpoint( $request )
    {
        $_data = [];
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Endpoint', ArrayUtils::get( $request, 'EndpointArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Delete endpoint request contains no 'Endpoint' field." );
            }

            $_data['EndpointArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            $_data['EndpointArn'] = $this->service->addArnPrefix( $request );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->deleteEndpoint( $_data ) )
            {
                return [ 'success' => true ];
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to delete endpoint '{$_data['EndpointArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }
}