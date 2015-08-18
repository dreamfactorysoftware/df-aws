<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
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
     * {@inheritdoc}
     */
    protected function getResourceIdentifier()
    {
        return 'SubscriptionArn';
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        if ($only_handlers) {
            return [];
        }
//        $refresh = $this->request->queryBool('refresh');

        $out = [];
        $token = null;
        try {
            do {
                if (empty($this->parentResource)) {
                    $result = $this->service->getConnection()->listSubscriptions(
                        [
                            'NextToken' => $token
                        ]
                    );
                } else {
                    $result = $this->service->getConnection()->listSubscriptionsByTopic(
                        [
                            'TopicArn'  => $this->parentResource,
                            'NextToken' => $token
                        ]
                    );
                }
                $topics = $result['Subscriptions'];
                $token = $result['NextToken'];

                if (!empty($topics)) {
                    $out = array_merge($out, $topics);
                }
            } while ($token);
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to retrieve subscriptions.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return $out;
    }

    /**
     * @param mixed $fields Use boolean, comma-delimited string, or array of properties
     *
     * @return ServiceResponseInterface
     */
    public function listResources($fields = null)
    {
        $resources = [];
        $result = $this->getResources();
        foreach ($result as $sub) {
            switch ($fields) {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $resources[] = $this->service->stripArnPrefix(ArrayUtils::get($sub, 'SubscriptionArn'));
                    break;
                case Sns::FORMAT_ARN:
                    $resources[] = ArrayUtils::get($sub, 'SubscriptionArn');
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $sub['Subscription'] = $this->service->stripArnPrefix(ArrayUtils::get($sub, 'SubscriptionArn'));
                    $resources[] = $sub;
                    break;
            }
        }

        return $resources;
    }

    protected function handleGET()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        } else {
            return $this->retrieveSubscription($this->resource);
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in subscription post request.');
        }

        if (empty($this->resource)) {
            if ($this->parentResource) {
                $payload['Topic'] = $this->parentResource;
            }

            return $this->createSubscription($payload);
        } else {
            return false;
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in subscription update request.');
        }

        if (!empty($this->resource)) {
            $payload['Subscription'] = $this->resource;
        }

        return $this->updateSubscription($payload);
    }

    protected function handlePATCH()
    {
        return $this->handlePUT();
    }

    protected function handleDELETE()
    {
        $payload = $this->request->getPayloadData();
        if (empty($this->resource)) {
            if (empty($payload)) {
                throw new BadRequestException('No data in subscription delete request.');
            }

            $this->deleteSubscription($payload);
        } else {
            $this->deleteSubscription($this->resource);
        }

        return ['success' => true];
    }

    /**
     * @param $resource
     *
     * @return array
     * @throws InternalServerErrorException
     * @throws NotFoundException
     */
    public function retrieveSubscription($resource)
    {
        $request = ['SubscriptionArn' => $this->service->addArnPrefix($resource)];

        try {
            if (null !== $result = $this->service->getConnection()->getSubscriptionAttributes($request)) {
                $out = array_merge($request, ArrayUtils::get($result->toArray(), 'Attributes', []));
                $out['Subscription'] = $this->service->stripArnPrefix($resource);

                return $out;
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to retrieve properties for '$resource'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function createSubscription($request)
    {
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Topic', ArrayUtils::get($request, 'TopicArn'));
            if (empty($name)) {
                throw new BadRequestException("Create Subscription request contains no 'Topic' field.");
            }

            $request['TopicArn'] = $this->service->addArnPrefix($name);
        } else {
            throw new BadRequestException("Create Subscription request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->subscribe($request)) {
                $arn = ArrayUtils::get($result->toArray(), 'SubscriptionArn', '');

                return ['Subscription' => $this->service->stripArnPrefix($arn), 'SubscriptionArn' => $arn];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to create subscription to  '{$request['TopicArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function updateSubscription($request)
    {
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Subscription', ArrayUtils::get($request, 'SubscriptionArn'));
            if (empty($name)) {
                throw new BadRequestException("Update subscription request contains no 'Subscription' field.");
            }

            $request['SubscriptionArn'] = $this->service->addArnPrefix($name);
        } else {
            throw new BadRequestException("Update subscription request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->setSubscriptionAttributes($request)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to update subscription '{$request['SubscriptionArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function deleteSubscription($request)
    {
        $data = [];
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Subscription', ArrayUtils::get($request, 'SubscriptionArn'));
            if (empty($name)) {
                throw new BadRequestException("Delete subscription request contains no 'Subscription' field.");
            }

            $data['SubscriptionArn'] = $this->service->addArnPrefix($name);
        } else {
            $data['SubscriptionArn'] = $this->service->addArnPrefix($request);
        }

        try {
            if (null !== $result = $this->service->getConnection()->unsubscribe($data)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to delete subscription '{$data['SubscriptionArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }
}