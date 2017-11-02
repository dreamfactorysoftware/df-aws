<?php

namespace DreamFactory\Core\Aws\Services;

use Aws\Iam\IamClient;
use Aws\Sns\SnsClient;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\Aws\Resources\BaseSnsResource;
use DreamFactory\Core\Aws\Resources\SnsApplication;
use DreamFactory\Core\Aws\Resources\SnsEndpoint;
use DreamFactory\Core\Aws\Resources\SnsSubscription;
use DreamFactory\Core\Aws\Resources\SnsTopic;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseRestService;
use DreamFactory\Core\Resources\BaseRestResource;

/**
 * Class Sns
 *
 * A service to handle Amazon Web Services SNS push notifications services
 * accessed through the REST API.
 *
 * @package DreamFactory\Core\Aws\Services
 */
class Sns extends BaseRestService
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * Service name
     */
    const CLIENT_NAME = 'Sns';
    /**
     * Resource tag for dealing with subscription
     */
    const ARN_PREFIX = 'arn:aws:sns:';
    /**
     * List types when requesting resources
     */
    const FORMAT_SIMPLE = 'simple';
    const FORMAT_ARN = 'arn';
    const FORMAT_FULL = 'full';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var SnsClient|null
     */
    protected $conn = null;
    /**
     * @var array
     */
    protected static $resources = [
        SnsTopic::RESOURCE_NAME        => [
            'name'       => SnsTopic::RESOURCE_NAME,
            'class_name' => SnsTopic::class,
            'label'      => 'SNS Topic',
        ],
        SnsSubscription::RESOURCE_NAME => [
            'name'       => SnsSubscription::RESOURCE_NAME,
            'class_name' => SnsSubscription::class,
            'label'      => 'SNS Subscription',
        ],
        SnsApplication::RESOURCE_NAME  => [
            'name'       => SnsApplication::RESOURCE_NAME,
            'class_name' => SnsApplication::class,
            'label'      => 'SNS Application',
        ],
        SnsEndpoint::RESOURCE_NAME     => [
            'name'       => SnsEndpoint::RESOURCE_NAME,
            'class_name' => SnsEndpoint::class,
            'label'      => 'SNS Endpoint',
        ],
    ];
    /**
     * @var string|null
     */
    protected $region = null;

    /**
     * @var string|null
     */
    protected $accountId = null;
    /**
     * @var string|null
     */
    protected $relatedResource = null;
    /**
     * @var string|null
     */
    protected $relatedResourceId = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new AwsSnsSvc
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings)
    {
        parent::__construct($settings);

        $config = (array)array_get($settings, 'config', []);
        //  Replace any private lookups
        Session::replaceLookups($config, true);
        // statically assign the our supported version
        $config['version'] = '2010-03-31';
        if (isset($config['key'])) {
            $config['credentials']['key'] = $config['key'];
        }
        if (isset($config['secret'])) {
            $config['credentials']['secret'] = $config['secret'];
        }

        try {
            $this->conn = new SnsClient($config);
            $this->setAccountId($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS SNS Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }
        $this->region = array_get($config, 'region');
    }

    /**
     * Sets the AWS account id.
     *
     * @param $config
     */
    protected function setAccountId($config)
    {
        $config['version'] = '2010-05-08';
        $iam = new IamClient($config);
        $user = $iam->getUser()->get('User');
        $arn = explode(':', array_get($user, 'Arn'));
        $this->accountId = array_get($arn, 4);
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->conn = null;
        } catch (\Exception $ex) {
            error_log("Failed to disconnect from service.\n{$ex->getMessage()}");
        }
    }

    /**
     * @throws InternalServerErrorException
     */
    public function getConnection()
    {
        if (empty($this->conn)) {
            throw new InternalServerErrorException('Service connection has not been initialized.');
        }

        return $this->conn;
    }

    /**
     * @param $name
     *
     * @return string
     */
    public function addArnPrefix($name)
    {
        if (0 !== substr_compare($name, static::ARN_PREFIX, 0, strlen(static::ARN_PREFIX))) {
            $name = static::ARN_PREFIX . $this->region . ':' . $this->accountId . ':' . $name;
        }

        return $name;
    }

    /**
     * @param $name
     *
     * @return string
     */
    public function stripArnPrefix($name)
    {
        if (0 === substr_compare($name, static::ARN_PREFIX, 0, strlen(static::ARN_PREFIX))) {
            $name = substr($name, strlen(static::ARN_PREFIX . $this->region . ':' . $this->accountId . ':'));
        }

        return $name;
    }

    /**
     * @param BaseRestResource $class
     * @param array            $info
     *
     * @return mixed
     */
    protected function instantiateResource($class, $info = [])
    {
        return new $class($this, $info);
    }

    /**
     * {@InheritDoc}
     */
    protected function handleResource(array $resources)
    {
        if (empty($this->relatedResource)) {
            return parent::handleResource($resources);
        }

        if (((SnsSubscription::RESOURCE_NAME == $this->relatedResource) &&
                (SnsTopic::RESOURCE_NAME == $this->resource)) ||
            ((SnsEndpoint::RESOURCE_NAME == $this->relatedResource) &&
                (SnsApplication::RESOURCE_NAME == $this->resource))
        ) {
            $child = array_get(static::$resources, $this->relatedResource);
            if (isset($child, $child['class_name'])) {
                $className = $child['class_name'];

                if (!class_exists($className)) {
                    throw new InternalServerErrorException('Service configuration class name lookup failed for resource ' .
                        $this->relatedResource);
                }

                /** @var BaseSnsResource $resource */
                $resource = $this->instantiateResource($className, $child);
                $resource->setParentResource($this->resourceId);

                $newPath = $this->resourceArray;
                array_shift($newPath);
                array_shift($newPath);
                array_shift($newPath);
                array_shift($newPath);
                array_shift($newPath);
                $newPath = implode('/', $newPath);

                return $resource->handleRequest($this->request, $newPath);
            }
        }

        throw new BadRequestException("Invalid related resource '{$this->relatedResource}' for resource '{$this->resource}'.");
    }

    /**
     * @return array
     */
    public function getAccessList()
    {
        $resources = parent::getAccessList();

        $name = SnsTopic::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $topic = new SnsTopic($this, static::$resources[SnsTopic::RESOURCE_NAME]);
        $result = $topic->listResources();
        foreach ($result as $name) {
            $name = SnsTopic::RESOURCE_NAME . '/' . $name;
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $resources[] = $name;
            }
        }

        $name = SnsSubscription::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $topic = new SnsSubscription($this, static::$resources[SnsSubscription::RESOURCE_NAME]);
        $result = $topic->listResources();
        foreach ($result as $name) {
            $name = SnsSubscription::RESOURCE_NAME . '/' . $name;
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $resources[] = $name;
            }
        }

        $name = SnsApplication::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $topic = new SnsApplication($this, static::$resources[SnsApplication::RESOURCE_NAME]);
        $result = $topic->listResources();
        foreach ($result as $name) {
            $name = SnsApplication::RESOURCE_NAME . '/' . $name;
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $resources[] = $name;
            }
        }

        $name = SnsEndpoint::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $topic = new SnsEndpoint($this, static::$resources[SnsEndpoint::RESOURCE_NAME]);
        $result = $topic->listResources();
        foreach ($result as $name) {
            $name = SnsEndpoint::RESOURCE_NAME . '/' . $name;
            $access = $this->getPermissions($name);
            if (!empty($access)) {
                $resources[] = $name;
            }
        }

        return $resources;
    }

    /**
     * {@inheritdoc}
     */
    public function getResources($only_handlers = false)
    {
        return ($only_handlers) ? static::$resources : array_values(static::$resources);
    }

    /**
     * @inheritdoc
     */
    protected function setResourceMembers($resourcePath = null)
    {
        parent::setResourceMembers($resourcePath);

        $this->resourceId = array_get($this->resourceArray, 1);

        $pos = 2;
        $more = array_get($this->resourceArray, $pos);

        if (!empty($more)) {
            if ((SnsApplication::RESOURCE_NAME == $this->resource) && (SnsEndpoint::RESOURCE_NAME !== $more)) {
                do {
                    $this->resourceId .= '/' . $more;
                    $pos++;
                    $more = array_get($this->resourceArray, $pos);
                } while (!empty($more) && (SnsEndpoint::RESOURCE_NAME !== $more));
            } elseif (SnsEndpoint::RESOURCE_NAME == $this->resource) {
                //  This will be the full resource path
                do {
                    $this->resourceId .= '/' . $more;
                    $pos++;
                    $more = array_get($this->resourceArray, $pos);
                } while (!empty($more));
            }
        }

        $this->relatedResource = array_get($this->resourceArray, $pos++);
        $this->relatedResourceId = array_get($this->resourceArray, $pos++);
        $more = array_get($this->resourceArray, $pos);

        if (!empty($more) && (SnsEndpoint::RESOURCE_NAME == $this->relatedResource)) {
            do {
                $this->relatedResourceId .= '/' . $more;
                $pos++;
                $more = array_get($this->resourceArray, $pos);
            } while (!empty($more));
        }

        return $this;
    }

    /**
     *
     */
    protected function validateResourceAccess()
    {
        $reqAction = $this->getRequestedAction();
        $fullResourcePath = null;
        if (!empty($this->resource)) {
            switch ($this->resource) {
                case SnsTopic::RESOURCE_NAME:
                    $fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                        if (SnsSubscription::RESOURCE_NAME == $this->relatedResource) {
                            $relatedResourcePath = $this->relatedResource . '/';
                            if (!empty($this->relatedResourceId)) {
                                $relatedResourcePath .= $this->stripArnPrefix($this->relatedResourceId);
                            }
                            $this->checkPermission($reqAction, $relatedResourcePath);
                        }
                    }
                    break;
                case SnsSubscription::RESOURCE_NAME:
                    $fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                    }
                    break;
                case SnsApplication::RESOURCE_NAME:
                    $fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                        if (SnsEndpoint::RESOURCE_NAME == $this->relatedResource) {
                            $relatedResourcePath = $this->relatedResource . '/';
                            if (!empty($this->relatedResourceId)) {
                                $relatedResourcePath .= $this->stripArnPrefix($this->relatedResourceId);
                            }
                            $this->checkPermission($reqAction, $relatedResourcePath);
                        }
                    }
                    break;
                case SnsEndpoint::RESOURCE_NAME:
                    $fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                    }
                    break;
                default:
                    break;
            }
        }

        $this->checkPermission($reqAction, $fullResourcePath);
    }

    /**
     * @inheritdoc
     */
    protected function preProcess()
    {
        //	Do validation here
        $this->validateResourceAccess();

        parent::preProcess();
    }

    /**
     * @inheritdoc
     */
    protected function handlePost()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No post detected in request.');
        }

        $result = $this->publish($payload);

        return $result;
    }

    /**
     * @param string      $request
     * @param string|null $resource_type
     * @param string|null $resource_id
     *
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws null
     */
    public function publish($request, $resource_type = null, $resource_id = null)
    {
        /** http://docs.aws.amazon.com/aws-sdk-php/latest/class-Aws.Sns.SnsClient.html#_publish */
        $data = [];
        if (is_array($request)) {
            if (null !== $message = array_get($request, 'Message')) {
                $data = array_merge($data, $request);
                if (is_array($message)) {
                    $data['Message'] = json_encode($message);

                    if (!array_key_exists('MessageStructure', $request)) {
                        $data['MessageStructure'] = 'json';
                    }
                }
            } else {
                //  This array is the message
                $data['Message'] = json_encode($request);
                $data['MessageStructure'] = 'json';
            }
        } else {
            //  This string is the message
            $data['Message'] = $request;
        }

        switch ($resource_type) {
            case SnsTopic::RESOURCE_NAME:
                $data['TopicArn'] = $this->addArnPrefix($resource_id);
                break;
            case SnsEndpoint::RESOURCE_NAME:
                $data['TargetArn'] = $this->addArnPrefix($resource_id);
                break;
            default:
                //  Must contain resource, either Topic or Endpoint ARN
                $topic = array_get($data, 'Topic', array_get($data, 'TopicArn'));
                $endpoint =
                    array_get($data, 'Endpoint',
                        array_get($data, 'EndpointArn', array_get($data, 'TargetArn')));
                if (!empty($topic)) {
                    $data['TopicArn'] = $this->addArnPrefix($topic);
                } elseif (!empty($endpoint)) {
                    $data['TargetArn'] = $this->addArnPrefix($endpoint);
                } else {
                    throw new BadRequestException("Publish request does not contain resource, either 'Topic' or 'Endpoint'.");
                }

                break;
        }

        try {
            if (null !== $result = $this->conn->publish($data)) {
                $id = array_get($result->toArray(), 'MessageId', '');

                return ['MessageId' => $id];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = static::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to push message.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    /**
     * Translates AWS SNS Exceptions to DF Exceptions
     * If not an AWS SNS Exception, then null is returned.
     *
     * @param \Exception  $exception
     * @param string|null $add_msg
     *
     * @return BadRequestException|InternalServerErrorException|NotFoundException|null
     */
    static public function translateException(\Exception $exception, $add_msg = null)
    {
        $msg = strval($add_msg) . $exception->getMessage();
        switch (get_class($exception)) {
            case 'Aws\Sns\Exception\AuthorizationErrorException':
            case 'Aws\Sns\Exception\EndpointDisabledException':
            case 'Aws\Sns\Exception\InvalidParameterException':
            case 'Aws\Sns\Exception\PlatformApplicationDisabledException':
            case 'Aws\Sns\Exception\SubscriptionLimitExceededException':
            case 'Aws\Sns\Exception\TopicLimitExceededException':
                return new BadRequestException($msg, $exception->getCode());
            case 'Aws\Sns\Exception\NotFoundException':
                return new NotFoundException($msg, $exception->getCode());
            case 'Aws\Sns\Exception\SnsException':
            case 'Aws\Sns\Exception\InternalErrorException':
                return new InternalServerErrorException($msg, $exception->getCode());
            default:
                return null;
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function getApiDocPaths()
    {
        $base = parent::getApiDocPaths();
        $capitalized = camelize($this->name);

        $paths = [
            '/'                                => [
                'post' => [
                    'summary'     => 'Send a message to a topic or endpoint.',
                    'description' => 'Post data should be an array of topic publish properties.',
                    'operationId' => 'publish' . $capitalized,
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsPublishRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsPublishResponse']
                    ],
                ],
            ],
            '/topic'                           => [
                'get'  => [
                    'summary'     => 'Retrieve all topics available for the push service.',
                    'description' => 'This returns the topics as resources.',
                    'operationId' => 'get' . $capitalized . 'Topics',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsTopicsResponse']
                    ],
                ],
                'post' => [
                    'summary'     => 'Create a topic.',
                    'description' => 'Post data should be an array of topic attributes including \'Name\'.',
                    'operationId' => 'create' . $capitalized . 'Topic',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsTopicRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsTopicIdentifier']
                    ],
                ],
            ],
            '/topic/{topic_name}'              => [
                'parameters' => [
                    [
                        'name'        => 'topic_name',
                        'description' => 'Full ARN or simplified name of the topic to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve topic definition for the given topic.',
                    'description' => 'This retrieves the topic, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'TopicAttributes',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsTopicAttributesResponse']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Send a message to the given topic.',
                    'description' => 'Post data should be an array of topic publish properties.',
                    'operationId' => 'publish' . $capitalized . 'Topic',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsPublishTopicRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsPublishResponse']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update a given topic\'s attributes.',
                    'description' => 'Post data should be an array of topic attributes including \'Name\'.',
                    'operationId' => 'set' . $capitalized . 'TopicAttributes',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsTopicAttributesRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete a given topic.',
                    'description' => 'Delete a given topic.',
                    'operationId' => 'delete' . $capitalized . 'Topic',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            '/topic/{topic_name}/subscription' => [
                'parameters' => [
                    [
                        'name'        => 'topic_name',
                        'description' => 'Full ARN or simplified name of the topic to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'List subscriptions available for the given topic.',
                    'description' => 'Return only the names of the subscriptions in an array.',
                    'operationId' => 'list' . $capitalized . 'SubscriptionsByTopic',
                    'parameters'  => [
                        [
                            'name'        => 'names_only',
                            'description' => 'Return only the names of the subscriptions in an array.',
                            'schema'      => ['type' => 'boolean', 'default' => true],
                            'in'          => 'query',
                            'required'    => true,
                        ],
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsComponentList']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Create a subscription for the given topic.',
                    'description' => 'Post data should be an array of subscription attributes including \'Name\'.',
                    'operationId' => 'subscribe' . $capitalized . 'Topic',
                    'parameters'  => [
                        [
                            'name'        => 'topic_name',
                            'description' => 'Full ARN or simplified name of the topic to perform operations on.',
                            'schema'      => ['type' => 'string'],
                            'in'          => 'path',
                            'required'    => true,
                        ],
                    ],
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsSubscriptionTopicRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsSubscriptionIdentifier']
                    ],
                ],
            ],
            '/subscription'                    => [
                'get'  => [
                    'summary'     => 'Retrieve all subscriptions as resources.',
                    'description' => 'This describes the topic, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'Subscriptions',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsSubscriptionsResponse']
                    ],
                ],
                'post' => [
                    'summary'     => 'Create a subscription.',
                    'description' => 'Post data should be an array of subscription attributes including \'Name\'.',
                    'operationId' => 'subscribeTo' . $capitalized,
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsSubscriptionRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsSubscriptionIdentifier']
                    ],
                ],
            ],
            '/subscription/{sub_name}'         => [
                'parameters' => [
                    [
                        'name'        => 'sub_name',
                        'description' => 'Full ARN or simplified name of the subscription to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve attributes for the given subscription.',
                    'description' => 'This retrieves the subscription, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'SubscriptionAttributes',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsSubscriptionAttributesResponse']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update a given subscription.',
                    'description' => 'Post data should be an array of subscription attributes including \'Name\'.',
                    'operationId' => 'set' . $capitalized . 'SubscriptionAttributes',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsSubscriptionAttributesRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete a given subscription.',
                    'description' => 'Delete a given subscription.',
                    'operationId' => 'unsubscribe' . $capitalized,
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            '/app'                             => [
                'get'  => [
                    'summary'     => 'Retrieve app definition for the given app.',
                    'description' => 'This describes the app, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'Apps',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsAppsResponse']
                    ],
                ],
                'post' => [
                    'summary'     => 'Create a given app.',
                    'description' => 'Post data should be an array of app attributes including \'Name\'.',
                    'operationId' => 'create' . $capitalized . 'App',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsAppRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsAppIdentifier']
                    ],
                ],
            ],
            '/app/{app_name}'                  => [
                'parameters' => [
                    [
                        'name'        => 'app_name',
                        'description' => 'Full ARN or simplified name of the app to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve app definition for the given app.',
                    'description' => 'This retrieves the app, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'AppAttributes',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsAppAttributesResponse']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update a given app.',
                    'description' => 'Post data should be an array of app attributes including \'Name\'.',
                    'operationId' => 'set' . $capitalized . 'AppAttributes',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsAppAttributesRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete a given app.',
                    'description' => 'Delete a given app.',
                    'operationId' => 'delete' . $capitalized . 'App',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
            '/app/{app_name}/endpoint'         => [
                'parameters' => [
                    [
                        'name'        => 'app_name',
                        'description' => 'Name of the application to get endpoints on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve endpoints for the given application.',
                    'description' => 'This describes the endpoints, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'EndpointsByApp',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsEndpointsResponse']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Create a endpoint for a given application.',
                    'description' => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    'operationId' => 'create' . $capitalized . 'AppEndpoint',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/AppEndpointRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsEndpointIdentifier']
                    ],
                ],
            ],
            '/endpoint'                        => [
                'get'  => [
                    'summary'     => 'Retrieve endpoint definition for the given endpoint.',
                    'description' => 'This describes the endpoint, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'Endpoints',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsEndpointsResponse']
                    ],
                ],
                'post' => [
                    'summary'     => 'Create a given endpoint.',
                    'description' => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    'operationId' => 'create' . $capitalized . 'Endpoint',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsEndpointRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsEndpointIdentifier']
                    ],
                ],
            ],
            '/endpoint/{endpoint_name}'        => [
                'parameters' => [
                    [
                        'name'        => 'endpoint_name',
                        'description' => 'Full ARN or simplified name of the endpoint to perform operations on.',
                        'schema'      => ['type' => 'string'],
                        'in'          => 'path',
                        'required'    => true,
                    ],
                ],
                'get'        => [
                    'summary'     => 'Retrieve endpoint definition for the given endpoint.',
                    'description' => 'This retrieves the endpoint, detailing its available properties.',
                    'operationId' => 'get' . $capitalized . 'EndpointAttributes',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsEndpointAttributesResponse']
                    ],
                ],
                'post'       => [
                    'summary'     => 'Send a message to the given endpoint.',
                    'description' => 'Post data should be an array of endpoint publish properties.',
                    'operationId' => 'publish' . $capitalized . 'Endpoint',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsPublishEndpointRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/SnsPublishResponse']
                    ],
                ],
                'put'        => [
                    'summary'     => 'Update a given endpoint.',
                    'description' => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    'operationId' => 'set' . $capitalized . 'EndpointAttributes',
                    'requestBody' => [
                        '$ref' => '#/components/requestBodies/SnsEndpointAttributesRequest'
                    ],
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
                'delete'     => [
                    'summary'     => 'Delete a given endpoint.',
                    'description' => 'Delete a given endpoint.',
                    'operationId' => 'delete' . $capitalized . 'Endpoint',
                    'responses'   => [
                        '200' => ['$ref' => '#/components/responses/Success']
                    ],
                ],
            ],
        ];

        return array_merge($base, $paths);
    }

    protected function getApiDocSchemas()
    {
        $commonAppAttributes = [
            'PlatformCredential'   => [
                'type'        => 'string',
                'description' => 'The credential received from the notification service.',
            ],
            'PlatformPrincipal'    => [
                'type'        => 'string',
                'description' => 'The principal received from the notification service.',
            ],
            'EventEndpointCreated' => [
                'type'        => 'string',
                'description' => 'Topic ARN to which EndpointCreated event notifications should be sent.',
            ],
            'EventEndpointUpdated' => [
                'type'        => 'string',
                'description' => 'Topic ARN to which EndpointUpdated event notifications should be sent.',
            ],
            'EventEndpointDeleted' => [
                'type'        => 'string',
                'description' => 'Topic ARN to which EndpointDeleted event notifications should be sent.',
            ],
            'EventDeliveryFailure' => [
                'type'        => 'string',
                'description' => 'Topic ARN to which DeliveryFailure event notifications should be sent upon Direct Publish delivery failure (permanent) to one of the application\'s endpoints.',
            ],
        ];

        $commonEndpointAttributes = [
            'CustomUserData' => [
                'type'        => 'string',
                'description' => 'Arbitrary user data to associate with the endpoint.',
            ],
            'Enabled'        => [
                'type'        => 'boolean',
                'description' => 'The flag that enables/disables delivery to the endpoint.',
            ],
            'Token'          => [
                'type'        => 'string',
                'description' => 'The device token, also referred to as a registration id, for an app and mobile device.',
            ],
        ];

        $models = [
            'SnsTopicsResponse'                 => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'array',
                        'description' => 'An array of identifying attributes for a topic, use either in requests.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsTopicIdentifier',
                        ],
                    ],
                ],
            ],
            'SnsTopicRequest'                   => [
                'type'       => 'object',
                'properties' => [
                    'Name' => [
                        'type'        => 'string',
                        'description' => 'The name of the topic you want to create.',
                        'required'    => true,
                    ],
                ],
            ],
            'SnsTopicIdentifier'                => [
                'type'       => 'object',
                'properties' => [
                    'Topic'    => [
                        'type'        => 'string',
                        'description' => 'The topic\'s simplified name.',
                    ],
                    'TopicArn' => [
                        'type'        => 'string',
                        'description' => 'The topic\'s Amazon Resource Name.',
                    ],
                ],
            ],
            'SnsTopicAttributesResponse'        => [
                'type'       => 'object',
                'properties' => [
                    'Topic'                   => [
                        'type'        => 'string',
                        'description' => 'The topic\'s simplified name.',
                    ],
                    'TopicArn'                => [
                        'type'        => 'string',
                        'description' => 'The topic\'s Amazon Resource Name.',
                    ],
                    'Owner'                   => [
                        'type'        => 'string',
                        'description' => 'The AWS account ID of the topic\'s owner.',
                    ],
                    'Policy'                  => [
                        'type'        => 'string',
                        'description' => 'The JSON serialization of the topic\'s access control policy.',
                    ],
                    'DisplayName'             => [
                        'type'        => 'string',
                        'description' => 'The human-readable name used in the "From" field for notifications to email and email-json endpoints.',
                    ],
                    'SubscriptionsPending'    => [
                        'type'        => 'string',
                        'description' => 'The number of subscriptions pending confirmation on this topic.',
                    ],
                    'SubscriptionsConfirmed'  => [
                        'type'        => 'string',
                        'description' => 'The number of confirmed subscriptions on this topic.',
                    ],
                    'SubscriptionsDeleted'    => [
                        'type'        => 'string',
                        'description' => 'The number of deleted subscriptions on this topic.',
                    ],
                    'DeliveryPolicy'          => [
                        'type'        => 'string',
                        'description' => 'The JSON serialization of the topic\'s delivery policy.',
                    ],
                    'EffectiveDeliveryPolicy' => [
                        'type'        => 'string',
                        'description' => 'The JSON serialization of the effective delivery policy that takes into account system defaults.',
                    ],
                ],
            ],
            'SnsTopicAttributesRequest'         => [
                'type'       => 'object',
                'properties' => [
                    'AttributeName'  => [
                        'type'        => 'string',
                        'description' => 'The name of the attribute you want to set.',
                        'enum'        => ['Policy', 'DisplayName', 'DeliveryPolicy'],
                        'default'     => 'DisplayName',
                        'required'    => true,
                    ],
                    'AttributeValue' => [
                        'type'        => 'string',
                        'description' => 'The value of the attribute you want to set.',
                    ],
                ],
            ],
            'SnsSubscriptionsResponse'          => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'array',
                        'description' => 'An array of identifying attributes for a subscription, use either in requests.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsSubscriptionIdentifier',
                        ],
                    ],
                ],
            ],
            'SnsSubscriptionRequest'            => [
                'type'       => 'object',
                'properties' => [
                    'Topic'    => [
                        'type'        => 'string',
                        'description' => 'The topic\'s simplified name or Amazon Resource Name.',
                        'required'    => true,
                    ],
                    'Protocol' => [
                        'type'        => 'string',
                        'description' => 'The protocol you want to use.',
                        'enum'        => ['http', 'https', 'email', 'email-json', 'sms', 'sqs', 'application'],
                        'required'    => true,
                    ],
                    'Endpoint' => [
                        'type'        => 'string',
                        'description' => 'The endpoint that you want to receive notifications, formats vary by protocol.',
                    ],
                ],
            ],
            'SnsSubscriptionTopicRequest'       => [
                'type'       => 'object',
                'properties' => [
                    'Protocol' => [
                        'type'        => 'string',
                        'description' => 'The protocol you want to use.',
                        'enum'        => ['http', 'https', 'email', 'email-json', 'sms', 'sqs', 'application'],
                        'required'    => true,
                    ],
                    'Endpoint' => [
                        'type'        => 'string',
                        'description' => 'The endpoint that you want to receive notifications, formats vary by protocol.',
                    ],
                ],
            ],
            'SnsSubscriptionIdentifier'         => [
                'type'       => 'object',
                'properties' => [
                    'Subscription'    => [
                        'type'        => 'string',
                        'description' => 'The subscription\'s simplified name.',
                    ],
                    'SubscriptionArn' => [
                        'type'        => 'string',
                        'description' => 'The subscription\'s Amazon Resource Name.',
                    ],
                ],
            ],
            'SnsSubscriptionAttributesResponse' => [
                'type'       => 'object',
                'properties' => [
                    'Subscription'                 => [
                        'type'        => 'string',
                        'description' => 'The subscription\'s simplified name.',
                    ],
                    'SubscriptionArn'              => [
                        'type'        => 'string',
                        'description' => 'The subscription\'s Amazon Resource Name.',
                    ],
                    'TopicArn'                     => [
                        'type'        => 'string',
                        'description' => 'The topic\'s Amazon Resource Name.',
                    ],
                    'Owner'                        => [
                        'type'        => 'string',
                        'description' => 'The AWS account ID of the topic\'s owner.',
                    ],
                    'ConfirmationWasAuthenticated' => [
                        'type'        => 'boolean',
                        'description' => 'True if the subscription confirmation request was authenticated.',
                    ],
                    'DeliveryPolicy'               => [
                        'type'        => 'string',
                        'description' => 'The JSON serialization of the topic\'s delivery policy.',
                    ],
                    'EffectiveDeliveryPolicy'      => [
                        'type'        => 'string',
                        'description' => 'The JSON serialization of the effective delivery policy that takes into account system defaults.',
                    ],
                ],
            ],
            'SnsSubscriptionAttributesRequest'  => [
                'type'       => 'object',
                'properties' => [
                    'AttributeName'  => [
                        'type'        => 'string',
                        'description' => 'The name of the attribute you want to set.',
                        'enum'        => ['DeliveryPolicy', 'RawMessageDelivery'],
                        'default'     => 'DeliveryPolicy',
                        'required'    => true,
                    ],
                    'AttributeValue' => [
                        'type'        => 'string',
                        'description' => 'The value of the attribute you want to set.',
                    ],
                ],
            ],
            'SnsAppResponse'                    => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'array',
                        'description' => 'An array of identifying attributes for a app, use either in requests.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsAppIdentifier',
                        ],
                    ],
                ],
            ],
            'SnsAppAttributes'                  => [
                'type'       => 'object',
                'properties' => $commonAppAttributes,
            ],
            'SnsAppRequest'                     => [
                'type'       => 'object',
                'properties' => [
                    'Name'       => [
                        'type'        => 'string',
                        'description' => 'Desired platform application name.',
                        'required'    => true,
                    ],
                    'Platform'   => [
                        'type'        => 'string',
                        'description' => 'One of the following supported platforms.',
                        'enum'        => ['ADM', 'APNS', 'APNS_SANDBOX', 'GCM'],
                        'required'    => true,
                    ],
                    'Attributes' => [
                        'type'        => 'SnsAppAttributes',
                        'description' => 'An array of key-value pairs containing platform-specified application attributes.',
                    ],
                ],
            ],
            'SnsAppIdentifier'                  => [
                'type'       => 'object',
                'properties' => [
                    'Application'            => [
                        'type'        => 'string',
                        'description' => 'The app\'s simplified name.',
                    ],
                    'PlatformApplicationArn' => [
                        'type'        => 'string',
                        'description' => 'The app\'s Amazon Resource Name.',
                    ],
                ],
            ],
            'SnsAppAttributesResponse'          => [
                'type'       => 'object',
                'properties' => [
                    'Application'            => [
                        'type'        => 'string',
                        'description' => 'The app\'s simplified name.',
                    ],
                    'PlatformApplicationArn' => [
                        'type'        => 'string',
                        'description' => 'The app\'s Amazon Resource Name.',
                    ],
                    'EventEndpointCreated'   => [
                        'type'        => 'string',
                        'description' => 'Topic ARN to which EndpointCreated event notifications should be sent.',
                    ],
                    'EventEndpointUpdated'   => [
                        'type'        => 'string',
                        'description' => 'Topic ARN to which EndpointUpdated event notifications should be sent.',
                    ],
                    'EventEndpointDeleted'   => [
                        'type'        => 'string',
                        'description' => 'Topic ARN to which EndpointDeleted event notifications should be sent.',
                    ],
                    'EventDeliveryFailure'   => [
                        'type'        => 'string',
                        'description' => 'Topic ARN to which DeliveryFailure event notifications should be sent upon Direct Publish delivery failure (permanent) to one of the application\'s endpoints.',
                    ],
                ],
            ],
            'SnsAppAttributesRequest'           => [
                'type'       => 'object',
                'properties' => [
                    'Attributes' => [
                        'type'        => 'SnsAppAttributes',
                        'description' => 'Mutable attributes on the endpoint.',
                        'required'    => true,
                    ],
                ],
            ],
            'SnsEndpointsResponse'              => [
                'type'       => 'object',
                'properties' => [
                    'resource' => [
                        'type'        => 'array',
                        'description' => 'An array of identifying attributes for a topic, use either in requests.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsEndpointIdentifier',
                        ],
                    ],
                ],
            ],
            'SnsAppEndpointRequest'             => [
                'type'       => 'object',
                'properties' => [
                    'Token'          => [
                        'type'        => 'string',
                        'description' => 'Unique identifier created by the notification service for an app on a device.',
                        'required'    => true,
                    ],
                    'CustomUserData' => [
                        'type'        => 'string',
                        'description' => 'Arbitrary user data to associate with the endpoint.',
                    ],
                    'Attributes'     => [
                        'type'        => 'array',
                        'description' => 'An array of key-value pairs containing endpoint attributes.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsMessageAttribute',
                        ],
                    ],
                ],
            ],
            'SnsEndpointRequest'                => [
                'type'       => 'object',
                'properties' => [
                    'Application'    => [
                        'type'        => 'string',
                        'description' => 'The application\'s simplified name or Amazon Resource Name.',
                        "required"    => true,
                    ],
                    'Token'          => [
                        'type'        => 'string',
                        'description' => 'Unique identifier created by the notification service for an app on a device.',
                        'required'    => true,
                    ],
                    'CustomUserData' => [
                        'type'        => 'string',
                        'description' => 'Arbitrary user data to associate with the endpoint.',
                    ],
                    'Attributes'     => [
                        'type'        => 'array',
                        'description' => 'An array of key-value pairs containing endpoint attributes.',
                        'items'       => [
                            '$ref' => '#/components/schemas/SnsMessageAttribute',
                        ],
                    ],
                ],
            ],
            'SnsEndpointIdentifier'             => [
                'type'       => 'object',
                'properties' => [
                    'Endpoint'    => [
                        'type'        => 'string',
                        'description' => 'The endpoint\'s simplified name.',
                    ],
                    'EndpointArn' => [
                        'type'        => 'string',
                        'description' => 'The endpoint\'s Amazon Resource Name.',
                    ],
                ],
            ],
            'SnsEndpointAttributesResponse'     => [
                'type'       => 'object',
                'properties' => [
                    'Endpoint'       => [
                        'type'        => 'string',
                        'description' => 'The endpoint\'s simplified name.',
                    ],
                    'EndpointArn'    => [
                        'type'        => 'string',
                        'description' => 'The endpoint\'s Amazon Resource Name.',
                    ],
                    'CustomUserData' => [
                        'type'        => 'string',
                        'description' => 'Arbitrary user data to associate with the endpoint.',
                    ],
                    'Enabled'        => [
                        'type'        => 'boolean',
                        'description' => 'The flag that enables/disables delivery to the endpoint.',
                    ],
                    'Token'          => [
                        'type'        => 'string',
                        'description' => 'The device token, also referred to as a registration id, for an app and mobile device.',
                    ],
                ],
            ],
            'SnsEndpointAttributes'             => [
                'type'       => 'object',
                'properties' => $commonEndpointAttributes,
            ],
            'SnsEndpointAttributesRequest'      => [
                'type'       => 'object',
                'properties' => [
                    'Attributes' => [
                        'type'        => 'SnsEndpointAttributes',
                        'description' => 'Mutable attributes on the endpoint.',
                        'required'    => true,
                    ],
                ],
            ],
            'SnsTopicMessage'                   => [
                'type'       => 'object',
                'properties' => [
                    'default' => [
                        'type'        => 'string',
                        'description' => 'This is sent when the message type is not specified below.',
                        'required'    => true,
                    ],
                    'email'   => [
                        'type'        => 'string',
                        'description' => 'Message sent to all email or email-json subscriptions.',
                    ],
                    'sqs'     => [
                        'type'        => 'string',
                        'description' => 'Message sent to all AWS SQS subscriptions.',
                    ],
                    'http'    => [
                        'type'        => 'string',
                        'description' => 'Message sent to all HTTP subscriptions.',
                    ],
                    'https'   => [
                        'type'        => 'string',
                        'description' => 'Message sent to all HTTPS subscriptions.',
                    ],
                    'sms'     => [
                        'type'        => 'string',
                        'description' => 'Message sent to all SMS subscriptions.',
                    ],
                    'APNS'    => [
                        'type'        => 'string',
                        'description' => '{\"aps\":{\"alert\": \"ENTER YOUR MESSAGE\",\"sound\":\"default\"} }',
                    ],
                    'GCM'     => [
                        'type'        => 'string',
                        'description' => '{ \"data\": { \"message\": \"ENTER YOUR MESSAGE\" } }',
                    ],
                    'ADM'     => [
                        'type'        => 'string',
                        'description' => '{ \"data\": { \"message\": \"ENTER YOUR MESSAGE\" } }',
                    ],
                    'BAIDU'   => [
                        'type'        => 'string',
                        'description' => '{\"title\":\"ENTER YOUR TITLE\",\"description\":\"ENTER YOUR DESCRIPTION\"}',
                    ],
                    'MPNS'    => [
                        'type'        => 'string',
                        'description' => '<?xml version=\"1.0\" encoding=\"utf-8\"?><wp:Notification xmlns:wp=\"WPNotification\"><wp:Tile><wp:Count>ENTER COUNT</wp:Count><wp:Title>ENTER YOUR MESSAGE</wp:Title></wp:Tile></wp:Notification>',
                    ],
                    'WNS'     => [
                        'type'        => 'string',
                        'description' => '<badge version=\"1\" value=\"23\"/>',
                    ],
                ],
            ],
            'SnsMessageAttributeData'           => [
                'type'       => 'object',
                'properties' => [
                    'DataType'    => [
                        'type'        => 'string',
                        'description' => 'Amazon SNS supports the following logical data types: String, Number, and Binary.',
                        'required'    => true,
                    ],
                    'StringValue' => [
                        'type'        => 'string',
                        'description' => 'Strings are Unicode with UTF8 binary encoding.',
                    ],
                    'BinaryValue' => [
                        'type'        => 'string',
                        'description' => 'Binary type attributes can store any binary data, for example, compressed data, encrypted data, or images.',
                    ],
                ],
            ],
            'SnsMessageAttribute'               => [
                'type'       => 'object',
                'properties' => [
                    '_user_defined_name_' => [
                        'type'        => 'SnsMessageAttributeData',
                        'description' => 'The name of the message attribute as defined by the user or specified platform.',
                    ],
                ],
            ],
            'SnsSimplePublishRequest'           => [
                'type'       => 'object',
                'properties' => [
                    'Topic'             => [
                        'type'        => 'string',
                        'description' => 'The simple name or ARN of the topic you want to publish to. Required if endpoint not given.',
                    ],
                    'Endpoint'          => [
                        'type'        => 'string',
                        'description' => 'The simple name or ARN of the endpoint you want to publish to. Required if topic not given.',
                    ],
                    'Message'           => [
                        'type'        => 'string',
                        'description' => 'The message you want to send to the topic, sends the same message to all transport protocols. ',
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsPublishRequest'                 => [
                'type'       => 'object',
                'properties' => [
                    'Topic'             => [
                        'type'        => 'string',
                        'description' => 'The simple name or ARN of the topic you want to publish to. Required if endpoint not given.',
                    ],
                    'Endpoint'          => [
                        'type'        => 'string',
                        'description' => 'The simple name or ARN of the endpoint you want to publish to. Required if topic not given.',
                    ],
                    'Message'           => [
                        'type'        => 'SnsTopicMessage',
                        'description' => 'The message you want to send to the topic. The \'default\' field is required.',
                        'required'    => true,
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageStructure'  => [
                        'type'        => 'string',
                        'description' => 'Set MessageStructure to "json".',
                        'default'     => 'json',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsSimplePublishTopicRequest'      => [
                'type'       => 'object',
                'properties' => [
                    'Message'           => [
                        'type'        => 'string',
                        'description' => 'The message you want to send to the topic, sends the same message to all transport protocols.',
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsPublishTopicRequest'            => [
                'type'       => 'object',
                'properties' => [
                    'Message'           => [
                        'type'        => 'SnsTopicMessage',
                        'description' => 'The message you want to send to the topic. The \'default\' field is required.',
                        'required'    => true,
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageStructure'  => [
                        'type'        => 'string',
                        'description' => 'Set MessageStructure to "json".',
                        'default'     => 'json',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsSimplePublishEndpointRequest'   => [
                'type'       => 'object',
                'properties' => [
                    'Message'           => [
                        'type'        => 'string',
                        'description' => 'The message you want to send to the topic, sends the same message to all transport protocols.',
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsPublishEndpointRequest'         => [
                'type'       => 'object',
                'properties' => [
                    'Message'           => [
                        'type'        => 'SnsTopicMessage',
                        'description' => 'The message you want to send to the topic. The \'default\' field is required.',
                        'required'    => true,
                    ],
                    'Subject'           => [
                        'type'        => 'string',
                        'description' => 'Optional parameter to be used as the "Subject" line when the message is delivered to email endpoints.',
                    ],
                    'MessageStructure'  => [
                        'type'        => 'string',
                        'description' => 'Set MessageStructure to "json".',
                        'default'     => 'json',
                    ],
                    'MessageAttributes' => [
                        'type'        => 'SnsMessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SnsPublishResponse'                => [
                'type'       => 'object',
                'properties' => [
                    'MessageId' => [
                        'type'        => 'string',
                        'description' => 'Unique identifier assigned to the published message.',
                    ],
                ],
            ],
        ];

        return $models;
    }
}