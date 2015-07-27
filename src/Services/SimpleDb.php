<?php
namespace DreamFactory\Core\Aws\Services;

use Aws\SimpleDb\SimpleDbClient;
use DreamFactory\Core\Aws\Utility\AwsSvcUtilities;
use DreamFactory\Core\Aws\Resources\SimpleDbSchema;
use DreamFactory\Core\Aws\Resources\SimpleDbTable;
use DreamFactory\Core\Exceptions\BadRequestException;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Core\Exceptions\NotFoundException;
use DreamFactory\Core\Services\BaseNoSqlDbService;
use DreamFactory\Core\Utility\Session;
use DreamFactory\Library\Utility\ArrayUtils;

/**
 * SimpleDb
 *
 * A service to handle SimpleDb NoSQL (schema-less) database
 * services accessed through the REST API.
 */
class SimpleDb extends BaseNoSqlDbService
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    const CLIENT_NAME = 'SimpleDb';

    const TABLE_INDICATOR = 'DomainName';

    //*************************************************************************
    //	Members
    //*************************************************************************

    /**
     * @var SimpleDbClient|null
     */
    protected $dbConn = null;
    /**
     * @var array
     */
    protected $resources = [
        SimpleDbSchema::RESOURCE_NAME => [
            'name'       => SimpleDbSchema::RESOURCE_NAME,
            'class_name' => SimpleDbSchema::class,
            'label'      => 'Schema',
        ],
        SimpleDbTable::RESOURCE_NAME  => [
            'name'       => SimpleDbTable::RESOURCE_NAME,
            'class_name' => SimpleDbTable::class,
            'label'      => 'Table',
        ],
    ];

    //*************************************************************************
    //	Methods
    //*************************************************************************

    /**
     * Create a new SimpleDb
     *
     * @param array $settings
     *
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function __construct($settings = array())
    {
        parent::__construct($settings);

        $config = ArrayUtils::clean(ArrayUtils::get($settings, 'config'));
        AwsSvcUtilities::updateCredentials($config, true);

        // set up a default table schema
        $parameters = ArrayUtils::clean(ArrayUtils::get($config, 'parameters'));
        Session::replaceLookups( $parameters );
        if (null !== ($table = ArrayUtils::get($parameters, 'default_create_table'))) {
            $this->defaultCreateTable = $table;
        }

        $this->dbConn = AwsSvcUtilities::createClient($config, static::CLIENT_NAME);
    }

    /**
     * Object destructor
     */
    public function __destruct()
    {
        try {
            $this->dbConn = null;
        } catch (\Exception $ex) {
            error_log("Failed to disconnect from database.\n{$ex->getMessage()}");
        }
    }

    /**
     * @throws \Exception
     */
    public function getConnection()
    {
        if (!isset($this->dbConn)) {
            throw new InternalServerErrorException('Database connection has not been initialized.');
        }

        return $this->dbConn;
    }

    public function getTables()
    {
        $out = array();
        $token = null;
        do {
            $result = $this->dbConn->listDomains(
                array(
                    'MaxNumberOfDomains' => 100, // arbitrary limit
                    'NextToken'         => $token
                )
            );
            $domains = $result['DomainNames'];
            $token = $result['NextToken'];

            if (!empty($domains)) {
                $out = array_merge($out, $domains);
            }
        } while ($result['LastEvaluatedTableName']);

        return $out;
    }

    // REST service implementation

    /**
     * @param string $name
     *
     * @return string
     * @throws BadRequestException
     * @throws NotFoundException
     */
    public function correctTableName(&$name)
    {
        static $existing = null;

        if (!$existing) {
            $existing = $this->getTables();
        }

        if (empty($name)) {
            throw new BadRequestException('Table name can not be empty.');
        }

        if (false === array_search($name, $existing)) {
            throw new NotFoundException("Table '$name' not found.");
        }

        return $name;
    }

    /**
     * {@InheritDoc}
     */
    protected function handleResource(array $resources)
    {
        try {
            return parent::handleResource($resources);
        } catch (NotFoundException $ex) {
            // If version 1.x, the resource could be a table
//            if ($this->request->getApiVersion())
//            {
//                $resource = $this->instantiateResource( Table::class, [ 'name' => $this->resource ] );
//                $newPath = $this->resourceArray;
//                array_shift( $newPath );
//                $newPath = implode( '/', $newPath );
//
//                return $resource->handleRequest( $this->request, $newPath, $this->outputFormat );
//            }

            throw $ex;
        }
    }
}