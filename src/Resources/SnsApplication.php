<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Contracts\ServiceResponseInterface;

/**
 * Class SnsApplication
 *
 * @package DreamFactory\Core\Aws\Resources
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
     * {@inheritdoc}
     */
    protected function getResourceIdentifier()
    {
        return 'PlatformApplicationArn';
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
                $result = $this->service->getConnection()->listPlatformApplications(
                    [
                        'NextToken' => $token
                    ]
                );
                $topics = $result['PlatformApplications'];
                $token = $result['NextToken'];

                if (!empty($topics)) {
                    $out = array_merge($out, $topics);
                }
            } while ($token);
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to retrieve applications.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return $out;
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

        $this->resource = ArrayUtils::get($this->resourceArray, 0);

        $pos = 1;
        $more = ArrayUtils::get($this->resourceArray, $pos);

        if (!empty($more)) {
            if (SnsEndpoint::RESOURCE_NAME !== $more) {
                do {
                    $this->resource .= '/' . $more;
                    $pos++;
                    $more = ArrayUtils::get($this->resourceArray, $pos);
                } while (!empty($more) && (SnsEndpoint::RESOURCE_NAME !== $more));
            }
        }

        return $this;
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
        foreach ($result as $app) {
            switch ($fields) {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $resources[] = $this->service->stripArnPrefix(ArrayUtils::get($app, 'PlatformApplicationArn'));
                    break;
                case Sns::FORMAT_ARN:
                    $resources[] = ArrayUtils::get($app, 'PlatformApplicationArn');
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $app['Application'] =
                        $this->service->stripArnPrefix(ArrayUtils::get($app, 'PlatformApplicationArn'));
                    $resources[] = $app;
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
            return $this->retrieveApplication($this->resource);
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in application post request.');
        }

        if (empty($this->resource)) {
            return $this->createApplication($payload);
        } else {
            return false;
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in application update request.');
        }

        if (!empty($this->resource)) {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateApplication($payload);
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
                throw new BadRequestException('No data in application delete request.');
            }

            $this->deleteApplication($payload);
        } else {
            $this->deleteApplication($this->resource);
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
    public function retrieveApplication($resource)
    {
        $request = ['PlatformApplicationArn' => $this->service->addArnPrefix($resource)];

        try {
            if (null !== $result = $this->service->getConnection()->getPlatformApplicationAttributes($request)) {
                $attributes = ArrayUtils::get($result->toArray(), 'Attributes');

                return [
                    'Application'            => $this->service->stripArnPrefix($resource),
                    'PlatformApplicationArn' => $this->service->addArnPrefix($resource),
                    'Attributes'             => $attributes
                ];
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

    public function createApplication($request)
    {
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Name');
            if (empty($name)) {
                throw new BadRequestException("Create application request contains no 'Name' field.");
            }
        } else {
            throw new BadRequestException("Create application request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->createPlatformApplication($request)) {
                $arn = ArrayUtils::get($result->toArray(), 'PlatformApplicationArn', '');

                return ['Application' => $this->service->stripArnPrefix($arn), 'PlatformApplicationArn' => $arn];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to create application '{$request['Name']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function updateApplication($request)
    {
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Application', ArrayUtils::get($request, 'PlatformApplicationArn'));
            if (empty($name)) {
                throw new BadRequestException("Update application request contains no 'Application' field.");
            }

            $request['PlatformApplicationArn'] = $this->service->addArnPrefix($name);
        } else {
            throw new BadRequestException("Update application request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->setPlatformApplicationAttributes($request)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException(
                "Failed to update application '{$request['PlatformApplicationArn']}'.\n{$ex->getMessage()}",
                $ex->getCode()
            );
        }

        return [];
    }

    public function deleteApplication($request)
    {
        $data = [];
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Application', ArrayUtils::get($request, 'PlatformApplicationArn'));
            if (empty($name)) {
                throw new BadRequestException("Delete application request contains no 'Application' field.");
            }

            $data['PlatformApplicationArn'] = $this->service->addArnPrefix($name);
        } else {
            $data['PlatformApplicationArn'] = $this->service->addArnPrefix($request);
        }

        try {
            if (null !== $result = $this->service->getConnection()->deletePlatformApplication($data)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException(
                "Failed to delete application '{$data['PlatformApplicationArn']}'.\n{$ex->getMessage()}",
                $ex->getCode()
            );
        }

        return [];
    }
}