<?php
namespace DreamFactory\Core\Aws\Components;

use DreamFactory\Library\Utility\ArrayUtils;
use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
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
    //	Constants
    //*************************************************************************

    const CLIENT_NAME = 'S3';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var S3Client
     */
    protected $_blobConn = null;

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * @throws DfException
     */
    protected function checkConnection()
    {
        if (empty($this->_blobConn)) {
            throw new DfException('No valid connection to blob file storage.');
        }
    }

    /**
     * @param array $config
     */
    public function __construct($config)
    {
        $this->_blobConn = AwsSvcUtilities::createClient($config, static::CLIENT_NAME);
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
        unset($this->_blobConn);
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
        $_buckets = $this->_blobConn->listBuckets()->get('Buckets');

        $_out = [];
        foreach ($_buckets as $_bucket) {
            $_name = rtrim($_bucket['Name']);
            $_out[] = ['name' => $_name, 'path' => $_name];
        }

        return $_out;
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

        return $this->_blobConn->doesBucketExist($container);
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
        $_name = ArrayUtils::get($properties, 'name', ArrayUtils::get($properties, 'path'));
        if (empty($_name)) {
            throw new BadRequestException('No name found for container in create request.');
        }
        try {
            $this->checkConnection();
            $this->_blobConn->createBucket(
                [
                    'Bucket' => $_name
                ]
            );

            return ['name' => $_name, 'path' => $_name];
        } catch (\Exception $ex) {
            throw new DfException("Failed to create container '$_name': " . $ex->getMessage());
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
            if ($this->_blobConn->doesBucketExist($container)) {
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
            $this->_blobConn->deleteBucket(
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

            return $this->_blobConn->doesObjectExist($container, $name);
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

            $_options = [
                'Bucket' => $container,
                'Key'    => $name,
                'Body'   => $blob
            ];

            if (!empty($type)) {
                $_options['ContentType'] = $type;
            }

            $_result = $this->_blobConn->putObject($_options);
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

            $_options = [
                'Bucket'     => $container,
                'Key'        => $name,
                'SourceFile' => $localFileName
            ];

            if (!empty($type)) {
                $_options['ContentType'] = $type;
            }

            $_result = $this->_blobConn->putObject($_options);
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

            $_options = [
                'Bucket'     => $container,
                'Key'        => $name,
                'CopySource' => urlencode($src_container . '/' . $src_name)
            ];

            $result = $this->_blobConn->copyObject($_options);
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

            $_options = [
                'Bucket' => $container,
                'Key'    => $name,
                'SaveAs' => $localFileName
            ];

            $_result = $this->_blobConn->getObject($_options);
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

            $_options = [
                'Bucket' => $container,
                'Key'    => $name
            ];

            $_result = $this->_blobConn->getObject($_options);

            return $_result['Body'];
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
            $this->_blobConn->deleteObject(
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
        $_options = [
            'Bucket' => $container,
            'Prefix' => $prefix
        ];

        if (!empty($delimiter)) {
            $_options['Delimiter'] = $delimiter;
        }

        //	No max-keys specified. Get everything.
        $_keys = [];

        do {
            /** @var \Aws\S3\Iterator\ListObjectsIterator $_list */
            $_list = $this->_blobConn->listObjects($_options);

            $_objects = $_list->get('Contents');

            if (!empty($_objects)) {
                foreach ($_objects as $_object) {
                    if (0 != strcasecmp($prefix, $_object['Key'])) {
                        $_keys[$_object['Key']] = true;
                    }
                }
            }

            $_objects = $_list->get('CommonPrefixes');

            if (!empty($_objects)) {
                foreach ($_objects as $_object) {
                    if (0 != strcasecmp($prefix, $_object['Prefix'])) {
                        if (!isset($_keys[$_object['Prefix']])) {
                            $_keys[$_object['Prefix']] = false;
                        }
                    }
                }
            }

            $_options['Marker'] = $_list->get('Marker');
        } while ($_list->get('IsTruncated'));

        $_options = [
            'Bucket' => $container,
            'Key'    => ''
        ];

        $_out = [];
        foreach ($_keys as $_key => $_isObject) {
            $_options['Key'] = $_key;

            if ($_isObject) {
                /** @var \Aws\S3\Iterator\ListObjectsIterator $_meta */
                $_meta = $this->_blobConn->headObject($_options);

                $_out[] = [
                    'name'           => $_key,
                    'content_type'   => $_meta->get('ContentType'),
                    'content_length' => intval($_meta->get('ContentLength')),
                    'last_modified'  => $_meta->get('LastModified')
                ];
            } else {
                $_out[] = [
                    'name'           => $_key,
                    'content_type'   => null,
                    'content_length' => 0,
                    'last_modified'  => null
                ];
            }
        }

        return $_out;
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

            /** @var \Aws\S3\Iterator\ListObjectsIterator $_result */
            $_result = $this->_blobConn->headObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );

            $_out = [
                'name'           => $name,
                'content_type'   => $_result->get('ContentType'),
                'content_length' => intval($_result->get('ContentLength')),
                'last_modified'  => $_result->get('LastModified')
            ];

            return $_out;
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

            /** @var \Aws\S3\Iterator\ListObjectsIterator $_result */
            $_result = $this->_blobConn->getObject(
                [
                    'Bucket' => $container,
                    'Key'    => $name
                ]
            );

            header('Last-Modified: ' . $_result->get('LastModified'));
            header('Content-Type: ' . $_result->get('ContentType'));
            header('Content-Length:' . intval($_result->get('ContentLength')));

            $_disposition =
                (isset($params['disposition']) && !empty($params['disposition'])) ? $params['disposition'] : 'inline';

            header('Content-Disposition: ' . $_disposition . '; filename="' . $name . '";');
            echo $_result->get('Body');
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