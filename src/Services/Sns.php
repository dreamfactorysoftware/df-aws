<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\Sns\SnsClient;
use DreamFactory\Core\Utility\ApiDocUtilities;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;
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
    const FORMAT_ARN    = 'arn';
    const FORMAT_FULL   = 'full';

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
    protected $resources = [
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

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config', []));
        //  Replace any private lookups
        Session::replaceLookups($config, true);
        // statically assign the our supported version
        $config['version'] = '2010-03-31';
        if (isset($config['key']))
        {
            $config['credentials']['key'] = $config['key'];
        }
        if (isset($config['secret']))
        {
            $config['credentials']['secret'] = $config['secret'];
        }

        try {
            $this->conn = new SnsClient($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS SNS Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }
        $this->region = ArrayUtils::get($config, 'region');
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

    public function addArnPrefix($name)
    {
        if (0 !== substr_compare($name, static::ARN_PREFIX, 0, strlen(static::ARN_PREFIX))) {
            $name = static::ARN_PREFIX . $this->region . ':' . $name;
        }

        return $name;
    }

    public function stripArnPrefix($name)
    {
        if (0 === substr_compare($name, static::ARN_PREFIX, 0, strlen(static::ARN_PREFIX))) {
            $name = substr($name, strlen(static::ARN_PREFIX . $this->region . ':'));
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
            $child = ArrayUtils::get($this->resources, $this->relatedResource);
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
                $newPath = implode('/', $newPath);

                return $resource->handleRequest($this->request, $newPath);
            }
        }

        throw new BadRequestException("Invalid related resource '{$this->relatedResource}' for resource '{$this->resource}'.");
    }

    public function getAccessList()
    {
        $resources = parent::getAccessList();

//        $refresh = $this->request->getParameterAsBool( 'refresh' );

        $name = SnsTopic::RESOURCE_NAME . '/';
        $access = $this->getPermissions($name);
        if (!empty($access)) {
            $resources[] = $name;
            $resources[] = $name . '*';
        }

        $topic = new SnsTopic($this, $this->resources[SnsTopic::RESOURCE_NAME]);
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

        $topic = new SnsSubscription($this, $this->resources[SnsSubscription::RESOURCE_NAME]);
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

        $topic = new SnsApplication($this, $this->resources[SnsApplication::RESOURCE_NAME]);
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

        $topic = new SnsEndpoint($this, $this->resources[SnsEndpoint::RESOURCE_NAME]);
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
        return ($only_handlers) ? $this->resources : array_values($this->resources);
    }

    /**
     * Apply the commonly used REST path members to the class.
     *
     * @param string $resourcePath
     *
     * @return $this
     */
    protected function setResourceMembers($resourcePath = null)
    {
        parent::setResourceMembers($resourcePath);

        $this->resourceId = ArrayUtils::get($this->resourceArray, 1);

        $pos = 2;
        $more = ArrayUtils::get($this->resourceArray, $pos);

        if (!empty($more)) {
            if ((SnsApplication::RESOURCE_NAME == $this->resource) && (SnsEndpoint::RESOURCE_NAME !== $more)) {
                do {
                    $this->resourceId .= '/' . $more;
                    $pos++;
                    $more = ArrayUtils::get($this->resourceArray, $pos);
                } while (!empty($more) && (SnsEndpoint::RESOURCE_NAME !== $more));
            } elseif (SnsEndpoint::RESOURCE_NAME == $this->resource) {
                //  This will be the full resource path
                do {
                    $this->resourceId .= '/' . $more;
                    $pos++;
                    $more = ArrayUtils::get($this->resourceArray, $pos);
                } while (!empty($more));
            }
        }

        $this->relatedResource = ArrayUtils::get($this->resourceArray, $pos++);
        $this->relatedResourceId = ArrayUtils::get($this->resourceArray, $pos++);
        $more = ArrayUtils::get($this->resourceArray, $pos);

        if (!empty($more) && (SnsEndpoint::RESOURCE_NAME == $this->relatedResource)) {
            do {
                $this->relatedResourceId .= '/' . $more;
                $pos++;
                $more = ArrayUtils::get($this->resourceArray, $pos);
            } while (!empty($more));
        }

        return $this;
    }

    /**
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
     * @return mixed
     */
    protected function preProcess()
    {
        //	Do validation here
        $this->validateResourceAccess();

        parent::preProcess();
    }

    /**
     * @return array
     * @throws BadRequestException
     */
    protected function handlePost()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No post detected in request.');
        }

        $this->triggerActionEvent($this->response);

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
            if (null !== $message = ArrayUtils::get($request, 'Message')) {
                $data = array_merge($data, $request);
                if (is_array($message)) {
                    $data['Message'] = json_encode($message);

                    if (!ArrayUtils::has($request, 'MessageStructure')) {
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
                $topic = ArrayUtils::get($data, 'Topic', ArrayUtils::get($data, 'TopicArn'));
                $endpoint =
                    ArrayUtils::get($data, 'Endpoint',
                        ArrayUtils::get($data, 'EndpointArn', ArrayUtils::get($data, 'TargetArn')));
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
                $id = ArrayUtils::get($result->toArray(), 'MessageId', '');

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
    public function getApiDocInfo()
    {
        $base = parent::getApiDocInfo();

        $apis = [
            [
                'path'        => '/{api_name}',
                'description' => 'Operations available for push notification services.',
                'operations'  => [
                    [
                        'method'           => 'POST',
                        'summary'          => 'simplePublish() - Send a simple message to a topic or endpoint.',
                        'nickname'         => 'simplePublish',
                        'notes'            => 'Post data should be an array of topic publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => ['{api_name}.publish'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic publish parameters.',
                                'allowMultiple' => false,
                                'type'          => 'SimplePublishRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'publish() - Send a message to a topic or endpoint.',
                        'nickname'         => 'publish',
                        'notes'            => 'Post data should be an array of topic publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => ['{api_name}.publish'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic message parameters.',
                                'allowMultiple' => false,
                                'type'          => 'PublishRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/topic',
                'description' => 'Operations for push topics.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getTopics() - Retrieve all topics available for the push service.',
                        'nickname'         => 'getTopics',
                        'notes'            => 'This returns the topics as resources.',
                        'event_name'       => ['{api_name}.topic.list'],
                        'type'             => 'GetTopicsResponse',
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listTopics() - List topics available for the push service.',
                        'nickname'         => 'listTopics',
                        'notes'            => 'Returns only the names of the topics in an array.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.topic.list'],
                        'parameters'       => [
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the topics in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'createTopic() - Create a topic.',
                        'nickname'         => 'createTopic',
                        'notes'            => 'Post data should be an array of topic attributes including \'Name\'.',
                        'type'             => 'TopicIdentifier',
                        'event_name'       => ['{api_name}.topic.create'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic attributes.',
                                'allowMultiple' => false,
                                'type'          => 'TopicRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/topic/{topic_name}',
                'description' => 'Operations for a specific push topic.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getTopicAttributes() - Retrieve topic definition for the given topic.',
                        'nickname'         => 'getTopicAttributes',
                        'notes'            => 'This retrieves the topic, detailing its available properties.',
                        'event_name'       => ['{api_name}.topic.{topic_name}.retrieve', '{api_name}.topic_retrieved'],
                        'type'             => 'TopicAttributesResponse',
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'simplePublishTopic() - Send a message to the given topic.',
                        'nickname'         => 'simplePublishTopic',
                        'notes'            => 'Post data should be an array of topic publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => ['{api_name}.topic.{topic_name}.publish', '{api_name}.topic_published'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic publish parameters.',
                                'allowMultiple' => false,
                                'type'          => 'SimplePublishTopicRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'publishTopic() - Send a message to the given topic.',
                        'nickname'         => 'publishTopic',
                        'notes'            => 'Post data should be an array of topic publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => ['{api_name}.topic.{topic_name}.publish', '{api_name}.topic_published'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic message parameters.',
                                'allowMultiple' => false,
                                'type'          => 'PublishTopicRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'PUT',
                        'summary'          => 'setTopicAttributes() - Update a given topic\'s attributes.',
                        'nickname'         => 'setTopicAttributes',
                        'type'             => 'Success',
                        'event_name'       => ['{api_name}.topic.{topic_name}.update', '{api_name}.topic_updated'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic attributes.',
                                'allowMultiple' => false,
                                'type'          => 'TopicAttributesRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of topic attributes including \'Name\'.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'deleteTopic() - Delete a given topic.',
                        'nickname'         => 'deleteTopic',
                        'notes'            => '',
                        'type'             => 'Success',
                        'event_name'       => ['{api_name}.topic.{topic_name}.delete', '{api_name}.topic_deleted'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/topic/{topic_name}/subscription',
                'description' => 'Operations for push subscriptions.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getSubscriptionsByTopic() - Retrieve subscriptions for the given topic.',
                        'nickname'         => 'getSubscriptionsByTopic',
                        'notes'            => 'This return the subscriptions as resources.',
                        'event_name'       => ['{api_name}.topic.{topic_name}.subscription.list'],
                        'type'             => 'GetSubscriptionsResponse',
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listSubscriptionsByTopic() - List subscriptions available for the given topic.',
                        'nickname'         => 'listSubscriptionsByTopic',
                        'notes'            => 'Return only the names of the subscriptions in an array.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.topic.{topic_name}.subscription.list'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the subscriptions in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'subscribeTopic() - Create a subscription for the given topic.',
                        'nickname'         => 'subscribeTopic',
                        'type'             => 'SubscriptionIdentifier',
                        'event_name'       => ['{api_name}.topic.{topic_name}.subscription.create'],
                        'parameters'       => [
                            [
                                'name'          => 'topic_name',
                                'description'   => 'Full ARN or simplified name of the topic to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of subscription attributes.',
                                'allowMultiple' => false,
                                'type'          => 'SubscriptionTopicRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of subscription attributes including \'Name\'.',
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/subscription',
                'description' => 'Operations for push subscriptions.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getSubscriptions() - Retrieve all subscriptions as resources.',
                        'nickname'         => 'getSubscriptions',
                        'notes'            => 'This describes the topic, detailing its available properties.',
                        'event_name'       => ['{api_name}.subscription.list'],
                        'type'             => 'GetSubscriptionsResponse',
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listSubscriptions() - List subscriptions available for the push service.',
                        'nickname'         => 'listSubscriptions',
                        'notes'            => 'See listed operations for each subscription available.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.subscription.list'],
                        'parameters'       => [
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the subscriptions in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'subscribe() - Create a subscription.',
                        'nickname'         => 'subscribe',
                        'type'             => 'SubscriptionIdentifier',
                        'event_name'       => ['{api_name}.subscription.create'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of subscription attributes.',
                                'allowMultiple' => false,
                                'type'          => 'SubscriptionRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of subscription attributes including \'Name\'.',
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/subscription/{sub_name}',
                'description' => 'Operations for a specific push subscription.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getSubscriptionAttributes() - Retrieve attributes for the given subscription.',
                        'nickname'         => 'getSubscriptionAttributes',
                        'event_name'       => [
                            '{api_name}.subscription.{subscription_name}.retrieve',
                            '{api_name}.subscription_retrieved'
                        ],
                        'type'             => 'SubscriptionAttributesResponse',
                        'parameters'       => [
                            [
                                'name'          => 'sub_name',
                                'description'   => 'Full ARN or simplified name of the subscription to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'This retrieves the subscription, detailing its available properties.',
                    ],
                    [
                        'method'           => 'PUT',
                        'summary'          => 'setSubscriptionAttributes() - Update a given subscription.',
                        'nickname'         => 'setSubscriptionAttributes',
                        'type'             => 'Success',
                        'event_name'       => [
                            '{api_name}.subscription.{subscription_name}.update',
                            '{api_name}.subscription_updated'
                        ],
                        'parameters'       => [
                            [
                                'name'          => 'sub_name',
                                'description'   => 'Full ARN or simplified name of the subscription to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of subscription attributes.',
                                'allowMultiple' => false,
                                'type'          => 'SubscriptionAttributesRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of subscription attributes including \'Name\'.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'unsubscribe() - Delete a given subscription.',
                        'nickname'         => 'unsubscribe',
                        'notes'            => '',
                        'type'             => 'Success',
                        'event_name'       => [
                            '{api_name}.subscription.{subscription_name}.delete',
                            '{api_name}.subscription_deleted'
                        ],
                        'parameters'       => [
                            [
                                'name'          => 'sub_name',
                                'description'   => 'Full ARN or simplified name of the subscription to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/app',
                'description' => 'Operations for push platform applications.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getApps() - Retrieve app definition for the given app.',
                        'nickname'         => 'getApps',
                        'event_name'       => ['{api_name}.app.list'],
                        'type'             => 'GetAppsResponse',
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'This describes the app, detailing its available properties.',
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listApps() - List apps available for the push service.',
                        'nickname'         => 'listApps',
                        'notes'            => 'See listed operations for each app available.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.app.list'],
                        'parameters'       => [
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the apps in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'createApp() - Create a given app.',
                        'nickname'         => 'createApp',
                        'type'             => 'AppIdentifier',
                        'event_name'       => ['{api_name}.app.create'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of app attributes.',
                                'allowMultiple' => false,
                                'type'          => 'AppRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of app attributes including \'Name\'.',
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/app/{app_name}',
                'description' => 'Operations for a specific push platform application.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getAppAttributes() - Retrieve app definition for the given app.',
                        'nickname'         => 'getAppAttributes',
                        'event_name'       => ['{api_name}.app.{app_name}.retrieve', '{api_name}.app_retrieved'],
                        'type'             => 'AppAttributesResponse',
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Full ARN or simplified name of the app to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'This retrieves the app, detailing its available properties.',
                    ],
                    [
                        'method'           => 'PUT',
                        'summary'          => 'setAppAttributes() - Update a given app.',
                        'nickname'         => 'setAppAttributes',
                        'type'             => 'Success',
                        'event_name'       => ['{api_name}.app.{app_name}.update', '{api_name}.app_updated'],
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Full ARN or simplified name of the app to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of app attributes.',
                                'allowMultiple' => false,
                                'type'          => 'AppAttributesRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of app attributes including \'Name\'.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'deleteApp() - Delete a given app.',
                        'nickname'         => 'deleteApp',
                        'notes'            => '',
                        'type'             => 'Success',
                        'event_name'       => ['{api_name}.app.{app_name}.delete', '{api_name}.app_deleted'],
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Full ARN or simplified name of the app to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/app/{app_name}/endpoint',
                'description' => 'Operations for push application endpoints.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getEndpointsByApp() - Retrieve endpoints for the given application.',
                        'nickname'         => 'getEndpointsByApp',
                        'event_name'       => ['{api_name}.endpoint.list'],
                        'type'             => 'GetEndpointsResponse',
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Name of the application to get endpoints on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'This describes the endpoints, detailing its available properties.',
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listEndpointsByApp() - List endpoints available for the push service.',
                        'nickname'         => 'listEndpointsByApp',
                        'notes'            => 'See listed operations for each endpoint available.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.endpoint.list'],
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Name of the application to get endpoints on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the endpoints in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'createAppEndpoint() - Create a endpoint for a given application.',
                        'nickname'         => 'createAppEndpoint',
                        'type'             => 'EndpointIdentifier',
                        'event_name'       => ['{api_name}.endpoint.create'],
                        'parameters'       => [
                            [
                                'name'          => 'app_name',
                                'description'   => 'Name of the application to create endpoints on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of endpoint attributes.',
                                'allowMultiple' => false,
                                'type'          => 'AppEndpointRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/endpoint',
                'description' => 'Operations for push application endpoints.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getEndpoints() - Retrieve endpoint definition for the given endpoint.',
                        'nickname'         => 'getEndpoints',
                        'notes'            => 'This describes the endpoint, detailing its available properties.',
                        'event_name'       => ['{api_name}.endpoint.list'],
                        'type'             => 'GetEndpointsResponse',
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'GET',
                        'summary'          => 'listEndpoints() - List endpoints available for the push service.',
                        'nickname'         => 'listEndpoints',
                        'notes'            => 'See listed operations for each endpoint available.',
                        'type'             => 'ComponentList',
                        'event_name'       => ['{api_name}.endpoint.list'],
                        'parameters'       => [
                            [
                                'name'          => 'names_only',
                                'description'   => 'Return only the names of the endpoints in an array.',
                                'allowMultiple' => false,
                                'type'          => 'boolean',
                                'paramType'     => 'query',
                                'required'      => true,
                                'default'       => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'createEndpoint() - Create a given endpoint.',
                        'nickname'         => 'createEndpoint',
                        'type'             => 'EndpointIdentifier',
                        'event_name'       => ['{api_name}.endpoint.create'],
                        'parameters'       => [
                            [
                                'name'          => 'body',
                                'description'   => 'Array of endpoint attributes.',
                                'allowMultiple' => false,
                                'type'          => 'EndpointRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    ],
                ],
            ],
            [
                'path'        => '/{api_name}/endpoint/{endpoint_name}',
                'description' => 'Operations for a specific push application endpoint.',
                'operations'  => [
                    [
                        'method'           => 'GET',
                        'summary'          => 'getEndpointAttributes() - Retrieve endpoint definition for the given endpoint.',
                        'nickname'         => 'getEndpointAttributes',
                        'event_name'       => [
                            '{api_name}.endpoint.{endpoint_name}.retrieve',
                            '{api_name}.endpoint_retrieved'
                        ],
                        'type'             => 'EndpointAttributesResponse',
                        'parameters'       => [
                            [
                                'name'          => 'endpoint_name',
                                'description'   => 'Full ARN or simplified name of the endpoint to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'This retrieves the endpoint, detailing its available properties.',
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'simplePublishEndpoint() - Send a message to the given endpoint.',
                        'nickname'         => 'simplePublishEndpoint',
                        'notes'            => 'Post data should be an array of endpoint publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => ['{api_name}.topic.{topic_name}.publish', '{api_name}.topic_published'],
                        'parameters'       => [
                            [
                                'name'          => 'endpoint_name',
                                'description'   => 'Full ARN or simplified name of the endpoint to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic publish parameters.',
                                'allowMultiple' => false,
                                'type'          => 'SimplePublishEndpointRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'POST',
                        'summary'          => 'publishEndpoint() - Send a message to the given endpoint.',
                        'nickname'         => 'publishEndpoint',
                        'notes'            => 'Post data should be an array of endpoint publish properties.',
                        'type'             => 'PublishResponse',
                        'event_name'       => [
                            '{api_name}.topic.{endpoint_name}.publish',
                            '{api_name}.endpoint_published'
                        ],
                        'parameters'       => [
                            [
                                'name'          => 'endpoint_name',
                                'description'   => 'Full ARN or simplified name of the endpoint to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of topic message parameters.',
                                'allowMultiple' => false,
                                'type'          => 'PublishEndpointRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                    [
                        'method'           => 'PUT',
                        'summary'          => 'setEndpointAttributes() - Update a given endpoint.',
                        'nickname'         => 'setEndpointAttributes',
                        'type'             => 'Success',
                        'event_name'       => [
                            '{api_name}.endpoint.{endpoint_name}.update',
                            '{api_name}.endpoint_updated'
                        ],
                        'parameters'       => [
                            [
                                'name'          => 'endpoint_name',
                                'description'   => 'Full ARN or simplified name of the endpoint to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                            [
                                'name'          => 'body',
                                'description'   => 'Array of endpoint attributes.',
                                'allowMultiple' => false,
                                'type'          => 'EndpointAttributesRequest',
                                'paramType'     => 'body',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                        'notes'            => 'Post data should be an array of endpoint attributes including \'Name\'.',
                    ],
                    [
                        'method'           => 'DELETE',
                        'summary'          => 'deleteEndpoint() - Delete a given endpoint.',
                        'nickname'         => 'deleteEndpoint',
                        'notes'            => '',
                        'type'             => 'Success',
                        'event_name'       => [
                            '{api_name}.endpoint.{endpoint_name}.delete',
                            '{api_name}.endpoint_deleted'
                        ],
                        'parameters'       => [
                            [
                                'name'          => 'endpoint_name',
                                'description'   => 'Full ARN or simplified name of the endpoint to perform operations on.',
                                'allowMultiple' => false,
                                'type'          => 'string',
                                'paramType'     => 'path',
                                'required'      => true,
                            ],
                        ],
                        'responseMessages' => ApiDocUtilities::getCommonResponses([400, 401, 500]),
                    ],
                ],
            ],
        ];

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
            'GetTopicsResponse'              => [
                'id'         => 'GetTopicsResponse',
                'properties' => [
                    'resource' => [
                        'type'        => 'Array',
                        'description' => 'An array of identifying attributes for a topic, use either in requests.',
                        'items'       => [
                            '$ref' => 'TopicIdentifier',
                        ],
                    ],
                ],
            ],
            'TopicRequest'                   => [
                'id'         => 'TopicRequest',
                'properties' => [
                    'Name' => [
                        'type'        => 'string',
                        'description' => 'The name of the topic you want to create.',
                        'required'    => true,
                    ],
                ],
            ],
            'TopicIdentifier'                => [
                'id'         => 'TopicIdentifier',
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
            'TopicAttributesResponse'        => [
                'id'         => 'TopicAttributesResponse',
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
            'TopicAttributesRequest'         => [
                'id'         => 'TopicAttributesRequest',
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
            'GetSubscriptionsResponse'       => [
                'id'         => 'GetSubscriptionsResponse',
                'properties' => [
                    'resource' => [
                        'type'        => 'Array',
                        'description' => 'An array of identifying attributes for a subscription, use either in requests.',
                        'items'       => [
                            '$ref' => 'SubscriptionIdentifier',
                        ],
                    ],
                ],
            ],
            'SubscriptionRequest'            => [
                'id'         => 'SubscriptionRequest',
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
            'SubscriptionTopicRequest'       => [
                'id'         => 'SubscriptionTopicRequest',
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
            'SubscriptionIdentifier'         => [
                'id'         => 'SubscriptionIdentifier',
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
            'SubscriptionAttributesResponse' => [
                'id'         => 'SubscriptionAttributesResponse',
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
            'SubscriptionAttributesRequest'  => [
                'id'         => 'SubscriptionAttributesRequest',
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
            'GetAppResponse'                 => [
                'id'         => 'GetAppResponse',
                'properties' => [
                    'resource' => [
                        'type'        => 'Array',
                        'description' => 'An array of identifying attributes for a app, use either in requests.',
                        'items'       => [
                            '$ref' => 'AppIdentifier',
                        ],
                    ],
                ],
            ],
            'AppAttributes'                  => [
                'id'         => 'AppAttributes',
                'properties' => $commonAppAttributes,
            ],
            'AppRequest'                     => [
                'id'         => 'AppRequest',
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
                        'type'        => 'AppAttributes',
                        'description' => 'An array of key-value pairs containing platform-specified application attributes.',
                    ],
                ],
            ],
            'AppIdentifier'                  => [
                'id'         => 'AppIdentifier',
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
            'AppAttributesResponse'          => [
                'id'         => 'AppAttributesResponse',
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
            'AppAttributesRequest'           => [
                'id'         => 'AppAttributesRequest',
                'properties' => [
                    'Attributes' => [
                        'type'        => 'AppAttributes',
                        'description' => 'Mutable attributes on the endpoint.',
                        'required'    => true,
                    ],
                ],
            ],
            'GetEndpointsResponse'           => [
                'id'         => 'GetEndpointsResponse',
                'properties' => [
                    'resource' => [
                        'type'        => 'Array',
                        'description' => 'An array of identifying attributes for a topic, use either in requests.',
                        'items'       => [
                            '$ref' => 'EndpointIdentifier',
                        ],
                    ],
                ],
            ],
            'AppEndpointRequest'             => [
                'id'         => 'AppEndpointRequest',
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
                        'type'        => 'Array',
                        'description' => 'An array of key-value pairs containing endpoint attributes.',
                        'items'       => [
                            '$ref' => 'MessageAttribute',
                        ],
                    ],
                ],
            ],
            'EndpointRequest'                => [
                'id'         => 'EndpointRequest',
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
                        'type'        => 'Array',
                        'description' => 'An array of key-value pairs containing endpoint attributes.',
                        'items'       => [
                            '$ref' => 'MessageAttribute',
                        ],
                    ],
                ],
            ],
            'EndpointIdentifier'             => [
                'id'         => 'EndpointIdentifier',
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
            'EndpointAttributesResponse'     => [
                'id'         => 'EndpointAttributesResponse',
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
            'EndpointAttributes'             => [
                'id'         => 'EndpointAttributes',
                'properties' => $commonEndpointAttributes,
            ],
            'EndpointAttributesRequest'      => [
                'id'         => 'EndpointAttributesRequest',
                'properties' => [
                    'Attributes' => [
                        'type'        => 'EndpointAttributes',
                        'description' => 'Mutable attributes on the endpoint.',
                        'required'    => true,
                    ],
                ],
            ],
            'TopicMessage'                   => [
                'id'         => 'TopicMessage',
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
            'MessageAttributeData'           => [
                'id'         => 'MessageAttributeData',
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
            'MessageAttribute'               => [
                'id'         => 'MessageAttribute',
                'properties' => [
                    '_user_defined_name_' => [
                        'type'        => 'MessageAttributeData',
                        'description' => 'The name of the message attribute as defined by the user or specified platform.',
                    ],
                ],
            ],
            'SimplePublishRequest'           => [
                'id'         => 'SimplePublishRequest',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'PublishRequest'                 => [
                'id'         => 'PublishRequest',
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
                        'type'        => 'TopicMessage',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SimplePublishTopicRequest'      => [
                'id'         => 'SimplePublishTopicRequest',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'PublishTopicRequest'            => [
                'id'         => 'PublishTopicRequest',
                'properties' => [
                    'Message'           => [
                        'type'        => 'TopicMessage',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'SimplePublishEndpointRequest'   => [
                'id'         => 'SimplePublishEndpointRequest',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'PublishEndpointRequest'         => [
                'id'         => 'PublishEndpointRequest',
                'properties' => [
                    'Message'           => [
                        'type'        => 'TopicMessage',
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
                        'type'        => 'MessageAttribute',
                        'description' => 'An associative array of string-data pairs containing user-specified message attributes.',
                    ],
                ],
            ],
            'PublishResponse'                => [
                'id'         => 'PublishResponse',
                'properties' => [
                    'MessageId' => [
                        'type'        => 'string',
                        'description' => 'Unique identifier assigned to the published message.',
                    ],
                ],
            ],
        ];

        $base['apis'] = array_merge($base['apis'], $apis);
        $base['models'] = array_merge($base['models'], $models);

        return $base;
    }
}