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

namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Exceptions\RestException;
use DreamFactory\Core\Contracts\ServiceResponseInterface;

/**
 * Class SnsTopic
 *
 * @package DreamFactory\Core\Aws\Resources
 */
class SnsTopic extends BaseSnsResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with topics
     */
    const RESOURCE_NAME = 'topic';

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
    protected function _getTopicsAsArray()
    {
        $_out = [];
        $_token = null;
        try
        {
            do
            {
                $_result = $this->service->getConnection()->listTopics(
                    [
                        'NextToken' => $_token
                    ]
                );
                $_topics = $_result['Topics'];
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

            throw new InternalServerErrorException( "Failed to retrieve topics.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return $_out;
    }

    /**
     * @param mixed $fields Use boolean, comma-delimited string, or array of properties
     *
     * @return ServiceResponseInterface
     */
    public function listResources( $fields = null )
    {
        $_resources = [];
        $_result = $this->_getTopicsAsArray();
        foreach ( $_result as $_topic )
        {
            switch ( $fields )
            {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $_resources[] = $this->service->stripArnPrefix( ArrayUtils::get( $_topic, 'TopicArn' ) );
                    break;
                case Sns::FORMAT_ARN:
                    $_resources[] = ArrayUtils::get( $_topic, 'TopicArn' );
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $_topic['Topic'] = $this->service->stripArnPrefix( ArrayUtils::get( $_topic, 'TopicArn' ) );
                    $_resources[] = $_topic;
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
            return $this->retrieveTopic( $this->resource );
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in topic post request.' );
        }

        if ( empty( $this->resource ) )
        {
            return $this->createTopic( $payload );
        }
        else
        {
            return $this->service->publish( $payload, static::RESOURCE_NAME, $this->resource );
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in topic update request.' );
        }

        if ( !empty( $this->resource ) )
        {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateTopic( $payload );
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
                throw new BadRequestException( 'No data in topic delete request.' );
            }

            $this->deleteTopic( $payload );
        }
        else
        {
            $this->deleteTopic( $this->resource );
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
    public function retrieveTopic( $resource )
    {
        $_request = [ 'TopicArn' => $this->service->addArnPrefix( $resource ) ];

        try
        {
            if ( null !== $_result = $this->service->getConnection()->getTopicAttributes( $_request ) )
            {
                $_out = ArrayUtils::get( $_result->toArray(), 'Attributes' );
                $_out['Topic'] = $this->service->stripArnPrefix( $resource );

                return $_out;
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

    public function createTopic( $request )
    {
        $_data = [];
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Name' );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Create Topic request contains no 'Name' field." );
            }

            $_data['Name'] = $_name;
        }
        else
        {
            $_data['Name'] = $request;
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->createTopic( $_data ) )
            {
                $_arn = ArrayUtils::get( $_result->toArray(), 'TopicArn', '' );

                return [ 'Topic' => $this->service->stripArnPrefix( $_arn ), 'TopicArn' => $_arn ];
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to create topic '{$_data['Name']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }

    public function updateTopic( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Topic', ArrayUtils::get( $request, 'TopicArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Update topic request contains no 'Topic' field." );
            }

            $request['TopicArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            throw new BadRequestException( "Update topic request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->setTopicAttributes( $request ) )
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

            throw new InternalServerErrorException( "Failed to update topic '{$request['TopicArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }

    public function deleteTopic( $request )
    {
        $_data = [];
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Topic', ArrayUtils::get( $request, 'TopicArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Delete Topic request contains no 'Topic' field." );
            }

            $_data['TopicArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            $_data['TopicArn'] = $this->service->addArnPrefix( $request );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->deleteTopic( $_data ) )
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

            throw new InternalServerErrorException( "Failed to delete topic '{$_data['TopicArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }
}