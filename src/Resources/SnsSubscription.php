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
 * Class SnsSubscription
 *
 * @package DreamFactory\Core\Aws\Resources
 */
class SnsSubscription extends BaseSnsResource
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Resource tag for dealing with subscription
     */
    const RESOURCE_NAME = 'subscription';

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
    protected function _getSubscriptionsAsArray()
    {
        $_out = [];
        $_token = null;
        try
        {
            do
            {
                if ( empty( $this->parentResource ) )
                {
                    $_result = $this->service->getConnection()->listSubscriptions(
                        [
                            'NextToken' => $_token
                        ]
                    );
                }
                else
                {
                    $_result = $this->service->getConnection()->listSubscriptionsByTopic(
                        [
                            'TopicArn'  => $this->parentResource,
                            'NextToken' => $_token
                        ]
                    );
                }
                $_topics = $_result['Subscriptions'];
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

            throw new InternalServerErrorException( "Failed to retrieve subscriptions.\n{$_ex->getMessage()}", $_ex->getCode() );
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
        $_result = $this->_getSubscriptionsAsArray();
        foreach ( $_result as $_sub )
        {
            switch ( $fields )
            {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $_resources[] = $this->service->stripArnPrefix( ArrayUtils::get( $_sub, 'SubscriptionArn' ) );
                    break;
                case Sns::FORMAT_ARN:
                    $_resources[] = ArrayUtils::get( $_sub, 'SubscriptionArn' );
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $_sub['Subscription'] = $this->service->stripArnPrefix( ArrayUtils::get( $_sub, 'SubscriptionArn' ) );
                    $_resources[] = $_sub;
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
            return $this->retrieveSubscription( $this->resource );
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if ( empty( $payload ) )
        {
            throw new BadRequestException( 'No data in subscription post request.' );
        }

        if ( empty( $this->resource ) )
        {
            if ( $this->parentResource )
            {
                $payload['Topic'] = $this->parentResource;
            }

            return $this->createSubscription( $payload );
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
            throw new BadRequestException( 'No data in subscription update request.' );
        }

        if ( !empty( $this->resource ) )
        {
            $payload['Subscription'] = $this->resource;
        }

        return $this->updateSubscription( $payload );
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
                throw new BadRequestException( 'No data in subscription delete request.' );
            }

            $this->deleteSubscription( $payload );
        }
        else
        {
            $this->deleteSubscription( $this->resource );
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
    public function retrieveSubscription( $resource )
    {
        $_request = [ 'SubscriptionArn' => $this->service->addArnPrefix( $resource ) ];

        try
        {
            if ( null !== $_result = $this->service->getConnection()->getSubscriptionAttributes( $_request ) )
            {
                $_out = array_merge( $_request, ArrayUtils::get( $_result->toArray(), 'Attributes', [] ) );
                $_out['Subscription'] = $this->service->stripArnPrefix( $resource );

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

    public function createSubscription( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Topic', ArrayUtils::get( $request, 'TopicArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Create Subscription request contains no 'Topic' field." );
            }

            $request['TopicArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            throw new BadRequestException( "Create Subscription request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->subscribe( $request ) )
            {
                $_arn = ArrayUtils::get( $_result->toArray(), 'SubscriptionArn', '' );

                return [ 'Subscription' => $this->service->stripArnPrefix( $_arn ), 'SubscriptionArn' => $_arn ];
            }
        }
        catch ( \Exception $_ex )
        {
            if ( null !== $_newEx = Sns::translateException( $_ex ) )
            {
                throw $_newEx;
            }

            throw new InternalServerErrorException( "Failed to create subscription to  '{$request['TopicArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }

    public function updateSubscription( $request )
    {
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Subscription', ArrayUtils::get( $request, 'SubscriptionArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Update subscription request contains no 'Subscription' field." );
            }

            $request['SubscriptionArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            throw new BadRequestException( "Update subscription request contains no fields." );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->setSubscriptionAttributes( $request ) )
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

            throw new InternalServerErrorException( "Failed to update subscription '{$request['SubscriptionArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }

    public function deleteSubscription( $request )
    {
        $_data = [];
        if ( is_array( $request ) )
        {
            $_name = ArrayUtils::get( $request, 'Subscription', ArrayUtils::get( $request, 'SubscriptionArn' ) );
            if ( empty( $_name ) )
            {
                throw new BadRequestException( "Delete subscription request contains no 'Subscription' field." );
            }

            $_data['SubscriptionArn'] = $this->service->addArnPrefix( $_name );
        }
        else
        {
            $_data['SubscriptionArn'] = $this->service->addArnPrefix( $request );
        }

        try
        {
            if ( null !== $_result = $this->service->getConnection()->unsubscribe( $_data ) )
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

            throw new InternalServerErrorException( "Failed to delete subscription '{$_data['SubscriptionArn']}'.\n{$_ex->getMessage()}", $_ex->getCode() );
        }

        return [];
    }
}