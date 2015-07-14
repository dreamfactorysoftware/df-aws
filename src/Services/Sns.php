<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\Sns\SnsClient;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
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
        AwsSvcUtilities::updateCredentials($config, true);

        $this->conn = AwsSvcUtilities::createClient($config, static::CLIENT_NAME);
        $this->region = ArrayUtils::get($config, 'region');
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->conn = null;
        } catch (\Exception $_ex) {
            error_log("Failed to disconnect from service.\n{$_ex->getMessage()}");
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
     * @return array
     */
    protected function getResources()
    {
        return $this->resources;
    }

    /**
     * @param array $resources
     *
     * @return array|bool
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
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

    /**
     * {@inheritdoc}
     */
    public function listResources($fields = null)
    {
        if (!$this->request->getParameterAsBool('as_access_components')) {
            return parent::listResources($fields);
        }

        $_resources = [];

//        $refresh = $this->request->queryBool( 'refresh' );

        $_name = SnsTopic::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $topic = new SnsTopic($this, $this->resources[SnsTopic::RESOURCE_NAME]);
        $_result = $topic->listResources($fields);
        foreach ($_result as $_name) {
            $_name = SnsTopic::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        $_name = SnsSubscription::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $topic = new SnsSubscription($this, $this->resources[SnsSubscription::RESOURCE_NAME]);
        $_result = $topic->listResources($fields);
        foreach ($_result as $_name) {
            $_name = SnsSubscription::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        $_name = SnsApplication::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $topic = new SnsApplication($this, $this->resources[SnsApplication::RESOURCE_NAME]);
        $_result = $topic->listResources($fields);
        foreach ($_result as $_name) {
            $_name = SnsApplication::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        $_name = SnsEndpoint::RESOURCE_NAME . '/';
        $_access = $this->getPermissions($_name);
        if (!empty($_access)) {
            $_resources[] = $_name;
            $_resources[] = $_name . '*';
        }

        $topic = new SnsEndpoint($this, $this->resources[SnsEndpoint::RESOURCE_NAME]);
        $_result = $topic->listResources($fields);
        foreach ($_result as $_name) {
            $_name = SnsEndpoint::RESOURCE_NAME . '/' . $_name;
            $_access = $this->getPermissions($_name);
            if (!empty($_access)) {
                $_resources[] = $_name;
            }
        }

        return $this->cleanResources($_resources);
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

        $_pos = 2;
        $_more = ArrayUtils::get($this->resourceArray, $_pos);

        if (!empty($_more)) {
            if ((SnsApplication::RESOURCE_NAME == $this->resource) && (SnsEndpoint::RESOURCE_NAME !== $_more)) {
                do {
                    $this->resourceId .= '/' . $_more;
                    $_pos++;
                    $_more = ArrayUtils::get($this->resourceArray, $_pos);
                } while (!empty($_more) && (SnsEndpoint::RESOURCE_NAME !== $_more));
            } elseif (SnsEndpoint::RESOURCE_NAME == $this->resource) {
                //  This will be the full resource path
                do {
                    $this->resourceId .= '/' . $_more;
                    $_pos++;
                    $_more = ArrayUtils::get($this->resourceArray, $_pos);
                } while (!empty($_more));
            }
        }

        $this->relatedResource = ArrayUtils::get($this->resourceArray, $_pos++);
        $this->relatedResourceId = ArrayUtils::get($this->resourceArray, $_pos++);
        $_more = ArrayUtils::get($this->resourceArray, $_pos);

        if (!empty($_more) && (SnsEndpoint::RESOURCE_NAME == $this->relatedResource)) {
            do {
                $this->relatedResourceId .= '/' . $_more;
                $_pos++;
                $_more = ArrayUtils::get($this->resourceArray, $_pos);
            } while (!empty($_more));
        }

        return $this;
    }

    /**
     */
    protected function validateResourceAccess()
    {
        $_reqAction = $this->getRequestedAction();
        $_fullResourcePath = null;
        if (!empty($this->resource)) {
            switch ($this->resource) {
                case SnsTopic::RESOURCE_NAME:
                    $_fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $_fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                        if (SnsSubscription::RESOURCE_NAME == $this->relatedResource) {
                            $_relatedResourcePath = $this->relatedResource . '/';
                            if (!empty($this->relatedResourceId)) {
                                $_relatedResourcePath .= $this->stripArnPrefix($this->relatedResourceId);
                            }
                            $this->checkPermission($_reqAction, $_relatedResourcePath);
                        }
                    }
                    break;
                case SnsSubscription::RESOURCE_NAME:
                    $_fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $_fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                    }
                    break;
                case SnsApplication::RESOURCE_NAME:
                    $_fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $_fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                        if (SnsEndpoint::RESOURCE_NAME == $this->relatedResource) {
                            $_relatedResourcePath = $this->relatedResource . '/';
                            if (!empty($this->relatedResourceId)) {
                                $_relatedResourcePath .= $this->stripArnPrefix($this->relatedResourceId);
                            }
                            $this->checkPermission($_reqAction, $_relatedResourcePath);
                        }
                    }
                    break;
                case SnsEndpoint::RESOURCE_NAME:
                    $_fullResourcePath = $this->resource . '/';
                    if (!empty($this->resourceId)) {
                        $_fullResourcePath .= $this->stripArnPrefix($this->resourceId);
                    }
                    break;
                default:
                    break;
            }
        }

        $this->checkPermission($_reqAction, $_fullResourcePath);
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

        $_result = $this->publish($payload);

        return $_result;
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
        $_data = [];
        if (is_array($request)) {
            if (null !== $_message = ArrayUtils::get($request, 'Message')) {
                $_data = array_merge($_data, $request);
                if (is_array($_message)) {
                    $_data['Message'] = json_encode($_message);

                    if (!ArrayUtils::has($request, 'MessageStructure')) {
                        $_data['MessageStructure'] = 'json';
                    }
                }
            } else {
                //  This array is the message
                $_data['Message'] = json_encode($request);
                $_data['MessageStructure'] = 'json';
            }
        } else {
            //  This string is the message
            $_data['Message'] = $request;
        }

        switch ($resource_type) {
            case SnsTopic::RESOURCE_NAME:
                $_data['TopicArn'] = $this->addArnPrefix($resource_id);
                break;
            case SnsEndpoint::RESOURCE_NAME:
                $_data['TargetArn'] = $this->addArnPrefix($resource_id);
                break;
            default:
                //  Must contain resource, either Topic or Endpoint ARN
                $_topic = ArrayUtils::get($_data, 'Topic', ArrayUtils::get($_data, 'TopicArn'));
                $_endpoint =
                    ArrayUtils::get($_data, 'Endpoint',
                        ArrayUtils::get($_data, 'EndpointArn', ArrayUtils::get($_data, 'TargetArn')));
                if (!empty($_topic)) {
                    $_data['TopicArn'] = $this->addArnPrefix($_topic);
                } elseif (!empty($_endpoint)) {
                    $_data['TargetArn'] = $this->addArnPrefix($_endpoint);
                } else {
                    throw new BadRequestException("Publish request does not contain resource, either 'Topic' or 'Endpoint'.");
                }

                break;
        }

        try {
            if (null !== $_result = $this->conn->publish($_data)) {
                $_id = ArrayUtils::get($_result->toArray(), 'MessageId', '');

                return ['MessageId' => $_id];
            }
        } catch (\Exception $_ex) {
            if (null !== $_newEx = static::translateException($_ex)) {
                throw $_newEx;
            }

            throw new InternalServerErrorException("Failed to push message.\n{$_ex->getMessage()}", $_ex->getCode());
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
        $_msg = strval($add_msg) . $exception->getMessage();
        switch (get_class($exception)) {
            case 'Aws\Sns\Exception\AuthorizationErrorException':
            case 'Aws\Sns\Exception\EndpointDisabledException':
            case 'Aws\Sns\Exception\InvalidParameterException':
            case 'Aws\Sns\Exception\PlatformApplicationDisabledException':
            case 'Aws\Sns\Exception\SubscriptionLimitExceededException':
            case 'Aws\Sns\Exception\TopicLimitExceededException':
                return new BadRequestException($_msg, $exception->getCode());
            case 'Aws\Sns\Exception\NotFoundException':
                return new NotFoundException($_msg, $exception->getCode());
            case 'Aws\Sns\Exception\SnsException':
            case 'Aws\Sns\Exception\InternalErrorException':
                return new InternalServerErrorException($_msg, $exception->getCode());
            default:
                return null;
        }
    }
}