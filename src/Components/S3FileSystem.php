<?php
namespace DreamFactory\Core\Aws\Components;

use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Components\RemoteFileSystem;
use DreamFactory\Core\Exceptions\DfException;
use DreamFactory\Core\Exceptions\BadRequestException;
use Aws\S3\S3Client;

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
        Session::replaceLookups( $config, true );
        // statically assign the our supported version
        $config['version'] = '2006-03-01';
        if (isset($config['key']))
        {
            $config['credentials']['key'] = $config['key'];
        }
        if (isset($config['secret']))
        {
            $config['credentials']['secret'] = $config['secret'];
        }

        try {
            $this->blobConn = new S3Client($config);
        } catch (\Exception $ex) {
            throw new InternalServerErrorException("AWS DynamoDb Service Exception:\n{$ex->getMessage()}",
                $ex->getCode());
        }

        $this->container = ArrayUtils::get($config, 'container');

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
        $name = ArrayUtils::get($properties, 'name', ArrayUtils::get($properties, 'path'));
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

            $result = $this->blobConn->putObject($options);
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

            $result = $this->blobConn->putObject($options);
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

            $result = $this->blobConn->copyObject($options);
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

            $result = $this->blobConn->getObject($options);
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
     *
     * @throws DfException
     */
    public function deleteBlob($container = '', $name = '')
    {
        try {
            $this->checkConnection();
            $this->blobConn->deleteObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );
        } catch (\Exception $ex) {
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
            'Bucket' => $container,
            'Prefix' => $prefix
        ];

        if (!empty($delimiter)) {
            $options['Delimiter'] = $delimiter;
        }

        //	No max-keys specified. Get everything.
        $keys = [];

        do {
            /** @var \Aws\Result $list */
            $list = $this->blobConn->listObjects($options);

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

            $options['Marker'] = $list->get('Marker');
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

    /**
     * @param string $container
     * @param string $name
     * @param array  $params
     *
     * @throws DfException
     */
    public function streamBlob($container, $name, $params = [])
    {
        try {
            $this->checkConnection();

            /** @var \Aws\Result $result */
            $result = $this->blobConn->getObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );

            header('Last-Modified: ' . $result->get('LastModified'));
            header('Content-Type: ' . $result->get('ContentType'));
            header('Content-Length:' . intval($result->get('ContentLength')));

            $disposition =
                (isset($params['disposition']) && !empty($params['disposition'])) ? $params['disposition'] : 'inline';

            header('Content-Disposition: ' . $disposition . '; filename="' . $name . '";');
            echo $result->get('Body');
        } catch (\Exception $ex) {
            if ('Resource could not be accessed.' == $ex->getMessage()) {
                $status_header = "HTTP/1.1 404 The specified file '$name' does not exist.";
                header($status_header);
                header('Content-Type: text/html');
            } else {
                throw new DfException('Failed to stream blob: ' . $ex->getMessage());
            }
        }
    }
}