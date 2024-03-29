<?php

namespace DreamFactory\Core\Aws\Components;

use Aws\S3\Exception\S3Exception;
use DreamFactory\Core\Enums\HttpStatusCodes;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Core\File\Components\RemoteFileSystem;
use DreamFactory\Core\Exceptions\DfException;
use DreamFactory\Core\Exceptions\BadRequestException;
use Aws\S3\S3Client;
use Illuminate\Support\Arr;

/**
 * Class S3FileSystem
 *
 * @package DreamFactory\Core\Aws\Components
 */
class S3FileSystem extends RemoteFileSystem
{
    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var S3Client
     */
    protected $blobConn = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @throws DfException
     */
    protected function checkConnection()
    {
        if (empty($this->blobConn)) {
            throw new DfException('No valid connection to blob file storage.');
        }
    }

    /**
     * @param array $config
     *
     * @throws InternalServerErrorException
     */
    public function __construct($config)
    {
        //  Replace any private lookups
        Session::replaceLookups($config, true);
        // statically assign the our supported version
        $config['version'] = '2006-03-01';
        if (isset($config['key'])) {
            $config['credentials']['key'] = $config['key'];
        }
        if (isset($config['secret'])) {
            $config['credentials']['secret'] = $config['secret'];
        }
        if (isset($config['proxy'])) {
            $config['http'] = ['proxy' => $config['proxy']];
        }

        try {
            $this->blobConn = new S3Client($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS DynamoDb Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }

        $this->container = Arr::get($config, 'container');

        if (!$this->containerExists($this->container)) {
            $this->createContainer(['name' => $this->container]);
        }
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        unset($this->blobConn);
    }

    /**
     * List all containers, just names if noted
     *
     * @param bool $include_properties If true, additional properties are retrieved
     *
     * @throws \Exception
     * @return array
     */
    public function listContainers($include_properties = false)
    {
        $this->checkConnection();

        if (!empty($this->container)) {
            return $this->listResource($include_properties);
        }

        /** @noinspection PhpUndefinedMethodInspection */
        $buckets = $this->blobConn->listBuckets()->get('Buckets');

        $out = [];
        foreach ($buckets as $bucket) {
            $name = rtrim($bucket['Name']);
            $out[] = ['name' => $name, 'path' => $name];
        }

        return $out;
    }

    /**
     * Gets all properties of a particular container, if options are false,
     * otherwise include content from the container
     *
     * @param  string $container Container name
     * @param  bool   $include_files
     * @param  bool   $include_folders
     * @param  bool   $full_tree
     *
     * @throws \Exception
     * @return array
     */
    public function getContainer($container, $include_files = true, $include_folders = true, $full_tree = false)
    {
        $result = [];
        if ($this->containerExists($container)) {
            $result = $this->getFolder($container, '', $include_files, $include_folders, $full_tree);
        }

        return $result;
    }

    public function getContainerProperties($container)
    {
        $result = [];

        if ($this->containerExists($container)) {
        }

        return $result;
    }

    /**
     * Check if a container exists
     *
     * @param  string $container Container name
     *
     * @return boolean
     */
    public function containerExists($container = '')
    {
        $this->checkConnection();

        return $this->blobConn->doesBucketExist($container);
    }

    /**
     * @param array $properties
     * @param array $metadata
     *
     * @throws BadRequestException
     * @throws DfException
     * @internal param array $properties
     * @return array
     */
    public function createContainer($properties, $metadata = [])
    {
        $name = Arr::get($properties, 'name', Arr::get($properties, 'path'));
        if (empty($name)) {
            throw new BadRequestException('No name found for container in create request.');
        }
        try {
            $this->checkConnection();
            $this->blobConn->createBucket(
                [
                    'Bucket' => $name
                ]
            );

            return ['name' => $name, 'path' => $name];
        } catch (\Exception $ex) {
            throw new DfException("Failed to create container '$name': " . $ex->getMessage());
        }
    }

    /**
     * Update a container with some properties
     *
     * @param string $container
     * @param array  $properties
     *
     * @throws DfException
     * @return void
     */
    public function updateContainerProperties($container, $properties = [])
    {
        $this->checkConnection();
        try {
            if ($this->blobConn->doesBucketExist($container)) {
                throw new \Exception("No container named '$container'");
            }
        } catch (\Exception $ex) {
            throw new DfException("Failed to update container '$container': " . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param bool   $force
     *
     * @throws DfException
     */
    public function deleteContainer($container = '', $force = false)
    {
        try {
            $this->checkConnection();
            $this->blobConn->deleteBucket(
                [
                    'Bucket' => $container
                ]
            );
        } catch (\Exception $ex) {
            throw new DfException('Failed to delete container "' . $container . '": ' . $ex->getMessage());
        }
    }

    /**
     * Check if a blob exists
     *
     * @param  string $container Container name
     * @param  string $name      Blob name
     *
     * @return boolean
     */
    public function blobExists($container = '', $name = '')
    {
        try {
            $this->checkConnection();

            // If the name ends in a / we know DF is looking for a "folder". S3 does not really have the concept
            // of folders per se so we need to check for the prefix.
            if (substr($name, -1) === '/') {
                $options = array(
                    "Bucket" => $container,
                    "Prefix" => $name,
                    // We only need to get one to prove the folder exists
                    "MaxKeys" => 1
                );

                $list = $this->blobConn->listObjectsV2($options);
                // if there is a "Contents" Array within our list, we know that there is a folder of that name
                return $list['Contents'] ? true : false;
            }

            // Just search for the file itself.
            return $this->blobConn->doesObjectExist($container, $name);
        } catch (\Exception $ex) {
            return false;
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $blob
     * @param string $type
     *
     * @throws DfException
     */
    public function putBlobData($container = '', $name = '', $blob = '', $type = '')
    {
        try {
            $this->checkConnection();

            $options = [
                'Bucket' => $container,
                'Key'    => $name,
                'Body'   => $blob
            ];

            if (!empty($type)) {
                $options['ContentType'] = $type;
            }

            $this->blobConn->putObject($options);
        } catch (\Exception $ex) {
            throw new DfException('Failed to create blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $localFileName
     * @param string $type
     *
     * @throws DfException
     */
    public function putBlobFromFile($container = '', $name = '', $localFileName = '', $type = '')
    {
        try {
            $this->checkConnection();

            $options = [
                'Bucket'     => $container,
                'Key'        => $name,
                'SourceFile' => $localFileName
            ];

            if (!empty($type)) {
                $options['ContentType'] = $type;
            }

            $this->blobConn->putObject($options);
        } catch (\Exception $ex) {
            throw new DfException('Failed to create blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param string $src_container
     * @param string $src_name
     * @param array  $properties
     *
     * @throws DfException
     *
     * @param array  $properties
     */
    public function copyBlob($container = '', $name = '', $src_container = '', $src_name = '', $properties = [])
    {
        try {
            $this->checkConnection();

            $options = [
                'Bucket'     => $container,
                'Key'        => $name,
                'CopySource' => urlencode($src_container . '/' . $src_name)
            ];

            $this->blobConn->copyObject($options);
        } catch (\Exception $ex) {
            throw new DfException('Failed to copy blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * Get blob
     *
     * @param  string $container     Container name
     * @param  string $name          Blob name
     * @param  string $localFileName Local file name to store downloaded blob
     *
     * @throws DfException
     */
    public function getBlobAsFile($container = '', $name = '', $localFileName = '')
    {
        try {
            $this->checkConnection();

            $options = [
                'Bucket' => $container,
                'Key'    => $name,
                'SaveAs' => $localFileName
            ];

            $this->blobConn->getObject($options);
        } catch (\Exception $ex) {
            throw new DfException('Failed to retrieve blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     *
     * @return string
     * @throws DfException
     */
    public function getBlobData($container = '', $name = '')
    {
        try {
            $this->checkConnection();

            $options = [
                'Bucket' => $container,
                'Key'    => $name
            ];

            $result = $this->blobConn->getObject($options);

            return $result['Body'];
        } catch (\Exception $ex) {
            throw new DfException('Failed to retrieve blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * @param string $container
     * @param string $name
     * @param bool   $noCheck
     *
     * @throws DfException
     */
    public function deleteBlob($container = '', $name = '', $noCheck = false)
    {
        try {
            $this->checkConnection();

            if (!$noCheck) {
                $this->blobConn->getObject(
                    [
                        'Bucket' => $container,
                        'Key'    => $name
                    ]
                );
            }

            $this->blobConn->deleteObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );
        } catch (\Exception $ex) {
            if ($ex instanceof S3Exception) {
                if ($ex->getStatusCode() === HttpStatusCodes::HTTP_NOT_FOUND) {
                    throw new NotFoundException("File '$name' was not found.'");
                }
            }
            throw new DfException('Failed to delete blob "' . $name . '": ' . $ex->getMessage());
        }
    }

    /**
     * List blobs
     *
     * @param  string $container Container name
     * @param  string $prefix    Optional. Filters the results to return only blobs whose name begins with the
     *                           specified prefix.
     * @param  string $delimiter Optional. Delimiter, i.e. '/', for specifying folder hierarchy
     *
     * @return array
     * @throws DfException
     */
    public function listBlobs($container = '', $prefix = '', $delimiter = '')
    {
        $options = [
            'Bucket'  => $container,
            'Prefix'  => $prefix,
            'MaxKeys' => 1000
        ];

        if (!empty($delimiter)) {
            $options['Delimiter'] = $delimiter;
        } else {
            // We only want to see the root files / folders otherwise the S3 call will take forever.
            $options['Delimiter'] = '/';
        }

        //	No max-keys specified. Get everything.
        $keys = [];

        do {
            /** @var \Aws\Result $list */
            $list = $this->blobConn->listObjectsV2($options);

            $objects = $list->get('Contents');

            if (!empty($objects)) {
                foreach ($objects as $object) {
                    if (0 != strcasecmp($prefix, $object['Key'])) {
                        $keys[$object['Key']] = true;
                    }
                }
            }

            $objects = $list->get('CommonPrefixes');

            if (!empty($objects)) {
                foreach ($objects as $object) {
                    if (0 != strcasecmp($prefix, $object['Prefix'])) {
                        if (!isset($keys[$object['Prefix']])) {
                            $keys[$object['Prefix']] = false;
                        }
                    }
                }
            }

            $options['ContinuationToken'] = $list->get('NextContinuationToken');
        } while ($list->get('IsTruncated'));

        $options = [
            'Bucket' => $container,
            'Key'    => ''
        ];

        $out = [];
        foreach ($keys as $key => $isObject) {
            $options['Key'] = $key;

            if ($isObject) {
                /** @var \Aws\Result $meta */
                $meta = $this->blobConn->headObject($options);

                $out[] = [
                    'name'           => $key,
                    'content_type'   => $meta->get('ContentType'),
                    'content_length' => intval($meta->get('ContentLength')),
                    'last_modified'  => $meta->get('LastModified')
                ];
            } else {
                $out[] = [
                    'name'           => $key,
                    'content_type'   => null,
                    'content_length' => 0,
                    'last_modified'  => null
                ];
            }
        }

        return $out;
    }

    /**
     * List blob
     *
     * @param  string $container Container name
     * @param  string $name      Blob name
     *
     * @return array instance
     * @throws DfException
     */
    public function getBlobProperties($container, $name)
    {
        try {
            $this->checkConnection();

            /** @var \Aws\Result $result */
            $result = $this->blobConn->headObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );

            $out = [
                'name'           => $name,
                'content_type'   => $result->get('ContentType'),
                'content_length' => intval($result->get('ContentLength')),
                'last_modified'  => $result->get('LastModified')
            ];

            return $out;
        } catch (\Exception $ex) {
            throw new DfException('Failed to list blob metadata: ' . $ex->getMessage());
        }
    }

    protected function getBlobInChunks($containerName, $name, $chunkSize): \Generator
    {
        try {
            $this->checkConnection();

            /** @var \Aws\Result $result */
            $result = $this->blobConn->getObject(['Bucket' => $containerName, 'Key' => $name]);
            $stream = &$result['Body'];
            $stream->rewind();

            while (!$stream->eof()) {
                yield $stream->read($chunkSize);
            }
        } catch (\Exception $ex) {
            throw new DfException('Failed to stream blob: ' . $ex->getMessage());
        }
    }
}