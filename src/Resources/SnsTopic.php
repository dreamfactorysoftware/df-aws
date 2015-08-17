<?php
namespace DreamFactory\Core\Aws\Resources;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Services\Sns;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
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
     * {@inheritdoc}
     */
    protected function getResourceIdentifier()
    {
        return 'TopicArn';
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
                $result = $this->service->getConnection()->listTopics(
                    [
                        'NextToken' => $token
                    ]
                );
                $topics = $result['Topics'];
                $token = $result['NextToken'];

                if (!empty($topics)) {
                    $out = array_merge($out, $topics);
                }
            } while ($token);
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to retrieve topics.\n{$ex->getMessage()}", $ex->getCode());
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
        foreach ($result as $topic) {
            switch ($fields) {
                case false:
                case Sns::FORMAT_SIMPLE:
                    $resources[] = $this->service->stripArnPrefix(ArrayUtils::get($topic, 'TopicArn'));
                    break;
                case Sns::FORMAT_ARN:
                    $resources[] = ArrayUtils::get($topic, 'TopicArn');
                    break;
                case true:
                case Sns::FORMAT_FULL:
                default:
                    $topic['Topic'] = $this->service->stripArnPrefix(ArrayUtils::get($topic, 'TopicArn'));
                    $resources[] = $topic;
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
            return $this->retrieveTopic($this->resource);
        }
    }

    protected function handlePOST()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in topic post request.');
        }

        if (empty($this->resource)) {
            return $this->createTopic($payload);
        } else {
            return $this->service->publish($payload, static::RESOURCE_NAME, $this->resource);
        }
    }

    protected function handlePUT()
    {
        $payload = $this->request->getPayloadData();
        if (empty($payload)) {
            throw new BadRequestException('No data in topic update request.');
        }

        if (!empty($this->resource)) {
            $payload['Topic'] = $this->resource;
        }

        return $this->updateTopic($payload);
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
                throw new BadRequestException('No data in topic delete request.');
            }

            $this->deleteTopic($payload);
        } else {
            $this->deleteTopic($this->resource);
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
    public function retrieveTopic($resource)
    {
        $request = ['TopicArn' => $this->service->addArnPrefix($resource)];

        try {
            if (null !== $result = $this->service->getConnection()->getTopicAttributes($request)) {
                $out = ArrayUtils::get($result->toArray(), 'Attributes');
                $out['Topic'] = $this->service->stripArnPrefix($resource);

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

    public function createTopic($request)
    {
        $data = [];
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Name');
            if (empty($name)) {
                throw new BadRequestException("Create Topic request contains no 'Name' field.");
            }

            $data['Name'] = $name;
        } else {
            $data['Name'] = $request;
        }

        try {
            if (null !== $result = $this->service->getConnection()->createTopic($data)) {
                $arn = ArrayUtils::get($result->toArray(), 'TopicArn', '');

                return ['Topic' => $this->service->stripArnPrefix($arn), 'TopicArn' => $arn];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to create topic '{$data['Name']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function updateTopic($request)
    {
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Topic', ArrayUtils::get($request, 'TopicArn'));
            if (empty($name)) {
                throw new BadRequestException("Update topic request contains no 'Topic' field.");
            }

            $request['TopicArn'] = $this->service->addArnPrefix($name);
        } else {
            throw new BadRequestException("Update topic request contains no fields.");
        }

        try {
            if (null !== $result = $this->service->getConnection()->setTopicAttributes($request)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to update topic '{$request['TopicArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }

    public function deleteTopic($request)
    {
        $data = [];
        if (is_array($request)) {
            $name = ArrayUtils::get($request, 'Topic', ArrayUtils::get($request, 'TopicArn'));
            if (empty($name)) {
                throw new BadRequestException("Delete Topic request contains no 'Topic' field.");
            }

            $data['TopicArn'] = $this->service->addArnPrefix($name);
        } else {
            $data['TopicArn'] = $this->service->addArnPrefix($request);
        }

        try {
            if (null !== $result = $this->service->getConnection()->deleteTopic($data)) {
                return ['success' => true];
            }
        } catch (\Exception $ex) {
            if (null !== $newEx = Sns::translateException($ex)) {
                throw $newEx;
            }

            throw new InternalServerErrorException("Failed to delete topic '{$data['TopicArn']}'.\n{$ex->getMessage()}",
                $ex->getCode());
        }

        return [];
    }
}