<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Contracts\ServiceResponseInterface;
use Illuminate\Support\Arr;

/**
 * Class SnsEndpoint
 *
 * @package DreamFactory\Core\Aws\Resources
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
     * {@inheritdoc}
     */
    protected static function getResourceIdentifier()
    {
        return 'EndpointArn';
    }

    /**
     * @return array
     * @throws BadRequestException
     * @throws InternalServerErrorException
     * @throws NotFoundException
     * @throws null
     */
    protected function getEndpointsAsArray($application)
    {
        if (empty($application)) {
            throw new BadRequestException('Platform application name required for retrieving endpoints.');
        }

        $application = $this->service->addArnPrefix($application);
        $out = [];
        $token = null;
        try {
            do {
                $result = $this->service->getConnection()->listEndpointsByPlatformApplication(
                    [
                        'PlatformApplicationArn' => $application,
                        'NextToken'              => $token
                    ]
                );
                $topics = $result['Endpoints'];
                $token = $result['NextToken'];

                if (!empty($topics)) {
                    $out = array_merge($out, $topics);
                }
            } while ($token);
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to retrieve endpoints.\n{$ex->getMessage()}",
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

        $this->resource = Arr::get($this->resourceArray, 0);

        $pos = 1;
        $more = Arr::get($this->resourceArray, $pos);

        if (!empty($more)) {
            //  This will be the full resource path
            do {
                $this->resource .= '/' . $more;
                $pos++;
                $more = Arr::get($this->resourceArray, $pos);
            } while (!empty($more));
        }

        return $this;
    }

    /** {@inheritdoc} */
    public function getResources()
    {
        if(!empty($this->parentResource)) {
            return $this->getEndpointsAsArray($this->parentResource);
        } else {
            $resources = $this->listResources(Sns::FORMAT_ARN);
            foreach ($resources as $key => $value){
                $resources[$key] = ['EndpointArn' => $value];
            }

            return $resources;
        }
    }

    /**
     * @param mixed $fields Use boolean, comma-delimited string, or array of properties
     *
     * @return ServiceResponseInterface
     * @throws BadRequestException|InternalServerErrorException|NotFoundException
     */
    public function listResources($fields = null)
    {
        $resources = [];
        if (empty($this->parentResource)) {
            $applications = [];
            try {
                $out = [];
                $token = null;
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

                foreach ($out as $app) {
                    $applications[] = Arr::get($app, 'PlatformApplicationArn');
                }
            } catch (\Exception $ex) {
                if (null !== $newEx = Sns::translateException($ex)) {
                    throw $newEx;
                }

                throw new InternalServerErrorException("Failed to retrieve applications.\n{$ex->getMessage()}",
                    $ex->getCode());
            }
        } else {
            $applications = [$this->parentResource];
        }

        foreach ($applications as $application) {
            $result = $this->getEndpointsAsArray($application);
            foreach ($result as $end) {
                switch ($fields) {
                    case false:
                    case Sns::FORMAT_SIMPLE:
                        $resources[] = $this->service->stripArnPrefix(Arr::get($end, 'EndpointArn'));
                        break;
                    case Sns::FORMAT_ARN:
                        $resources[] = Arr::get($end, 'EndpointArn');
                        break;
                    case true:
                    case Sns::FORMAT_FULL:
                    default:
                        $end['Endpoint'] = $this->service->stripArnPrefix(Arr::get($end, 'EndpointArn'));
                        $resources[] = $end;
                        break;
                }
            }
        }

        return $resources;
    }

    /** {@inheritdoc} */
    protected function handleGET()
    {
        if (empty($this->resource)) {
            return parent::handleGET();
        } else {
            return $this->retrieveEndpoint($this->resource);
        }
    }

    /** {@inheritdoc} */
    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in endpoint post request.');
        }

        if (empty($this->resource)) {
            return $this->createEndpoint($payload);
        } else {
            return $this->service->publish($payload, SnsEndpoint::RESOURCE_NAME, $this->resource);
        }
    }

    /** {@inheritdoc} */
    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in endpoint update request.');
        }

        if (!empty($this->resource)) {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateEndpoint($payload);
    }

    /** {@inheritdoc} */
    protected function handlePATCH()
    {
        return $this->handlePUT();
    }

    /** {@inheritdoc} */
    protected function handleDELETE()
    {
        $payload = $this->request->getPayloadData();
        if (empty($this->resource)) {
            if (empty($payload)) {
                throw new BadRequestException('No data in endpoint delete request.');
            }

            $this->deleteEndpoint($payload);
        } else {
            $this->deleteEndpoint($this->resource);
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
    public function retrieveEndpoint($resource)
    {
        $request = ['EndpointArn' => $this->service->addArnPrefix($resource)];

        try {
            if (null !== $result = $this->service->getConnection()->getEndpointAttributes($request)) {
                $attributes = Arr::get($result->toArray(), 'Attributes');

                return [
                    'Endpoint'    => $this->service->stripArnPrefix($resource),
                    'EndpointArn' => $this->service->addArnPrefix($resource),
                    'Attributes'  => $attributes
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

    /**
     * @param $request
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function createEndpoint($request)
    {
        if (is_array($request)) {
            $name = Arr::get($request, 'Application', Arr::get($request, 'PlatformApplicationArn'));
            if (empty($name)) {
                throw new BadRequestException("Create endpoint request contains no 'Application' field.");
            }
            $request['PlatformApplicationArn'] = $this->service->addArnPrefix($name);
            $name = Arr::get($request, 'Token');
            if (empty($name)) {
                throw new BadRequestException("Create endpoint request contains no 'Token' field.");
            }
        } else {
            throw new BadRequestException("Create endpoint request contains fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->createPlatformEndpoint($request)) {
                $arn = Arr::get($result->toArray(), 'EndpointArn', '');

                return ['Endpoint' => $this->service->stripArnPrefix($arn), 'EndpointArn' => $arn];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException(
                "Failed to create endpoint for '{$request['PlatformApplicationArn']}'.\n{$ex->getMessage()}",
                $ex->getCode()
            );
        }

        return [];
    }

    /**
     * @param $request
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function updateEndpoint($request)
    {
        if (is_array($request)) {
            $name = Arr::get($request, 'Endpoint', Arr::get($request, 'EndpointArn'));
            if (empty($name)) {
                throw new BadRequestException("Update endpoint request contains no 'Endpoint' field.");
            }

            $request['EndpointArn'] = $this->service->addArnPrefix($name);
        } else {
            throw new BadRequestException("Update endpoint request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->setEndpointAttributes($request)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to update endpoint '{$request['EndpointArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    /**
     * @param $request
     *
     * @return array
     * @throws \DreamFactory\Core\Exceptions\BadRequestException
     * @throws \DreamFactory\Core\Exceptions\InternalServerErrorException
     */
    public function deleteEndpoint($request)
    {
        $data = [];
        if (is_array($request)) {
            $name = Arr::get($request, 'Endpoint', Arr::get($request, 'EndpointArn'));
            if (empty($name)) {
                throw new BadRequestException("Delete endpoint request contains no 'Endpoint' field.");
            }

            $data['EndpointArn'] = $this->service->addArnPrefix($name);
        } else {
            $data['EndpointArn'] = $this->service->addArnPrefix($request);
        }

        try {
            if (null !== $result = $this->service->getConnection()->deleteEndpoint($data)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to delete endpoint '{$data['EndpointArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }
}