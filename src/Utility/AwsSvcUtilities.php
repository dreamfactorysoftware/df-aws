<?php
namespace DreamFactory\Core\Aws\Utility;

use Aws\Common\Aws;
use Aws\Common\Client\AbstractClient;
use Aws\Common\Enum\Region;
use DreamFactory\Core\Exceptions\InternalServerErrorException;
use DreamFactory\Library\Utility\ArrayUtils;

/**
 * AwsSvcUtilities.php
 *
 * A utility class for using Amazon Web Services services accessed through the REST API.
 */
class AwsSvcUtilities
{
    //*************************************************************************
    //	Constants
    //*************************************************************************

    /**
     * string - Default region for services requiring a region, but not provisioned one.
     */
    const DEFAULT_REGION = Region::US_WEST_1;

    /**
     * Some credentials had old naming convention, switch to AWS naming convention.
     * Also applies any lookups.
     *
     * @param      $credentials
     * @param bool $require_region
     */
    public static function updateCredentials(&$credentials, $require_region = true)
    {
        //  Replace any private lookups
        //Session::replaceLookups( $credentials, true );

        //  Fix credentials
        if (null !== $_accessKey = ArrayUtils::get($credentials, 'access_key', null, true)) {
            unset($credentials['access_key']);
            // old way, replace with 'key'
            ArrayUtils::set($credentials, 'key', $_accessKey);
        }

        if (null !== $_secretKey = ArrayUtils::get($credentials, 'secret_key', null, true)) {
            unset($credentials['secret_key']);
            // old way, replace with 'key'
            ArrayUtils::set($credentials, 'secret', $_secretKey);
        }

        if ($require_region) {
            if (null === $_region = ArrayUtils::get($credentials, 'region', null, true)) {
                // use a default region if not present
                ArrayUtils::set($credentials, 'region', static::DEFAULT_REGION);
            }
        }
    }

    /**
     * Use the preferred factory method to create AWS service clients
     *
     * @param $credentials
     * @param $factory
     *
     * @return null | AbstractClient
     * @throws InternalServerErrorException
     */
    public static function createClient($credentials, $factory)
    {
        $_client = null;

        try {
            $_aws = Aws::factory($credentials);

            $_client = $_aws->get($factory);
        } catch (\Exception $_ex) {
            throw new InternalServerErrorException("Amazon $factory Service Exception:\n{$_ex->getMessage()}",
                $_ex->getCode());
        }

        return $_client;
    }
}