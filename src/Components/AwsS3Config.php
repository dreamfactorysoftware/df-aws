<?php
namespace DreamFactory\Core\Aws\Components;

use DreamFactory\Core\Aws\Models\AwsConfig;
use DreamFactory\Core\Contracts\ServiceConfigHandlerInterface;
use DreamFactory\Core\File\Models\FilePublicPath;
use DreamFactory\Library\Utility\ArrayUtils;

class AwsS3Config implements ServiceConfigHandlerInterface
{
    /**
     * {@inheritdoc}
     */
    public static function getConfig($id, $protect = true)
    {
        $config = [];

        /** @var AwsConfig $awsConfig */
        if (!empty($awsConfig = AwsConfig::find($id))) {
            $awsConfig->protectedView = $protect;
            $config = $awsConfig->toArray();
        }

        /** @var FilePublicPath $pathConfig */
        if (!empty($pathConfig = FilePublicPath::find($id))) {
            $config = array_merge($config, $pathConfig->toArray());
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function validateConfig($config, $create = true)
    {
        return (AwsConfig::validateConfig($config, $create) && FilePublicPath::validateConfig($config, $create));
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config)
    {
        /** @var AwsConfig $awsConfig */
        $awsConfig = AwsConfig::find($id);
        /** @var FilePublicPath $pathConfig */
        $pathConfig = FilePublicPath::find($id);
        $configPath = [
            'public_path' => array_get($config, 'public_path'),
            'container'   => array_get($config, 'container')
        ];
        $configAws = [
            'service_id' => array_get($config, 'service_id'),
            'key'        => array_get($config, 'key'),
            'secret'     => array_get($config, 'secret'),
            'region'     => array_get($config, 'region')
        ];

        ArrayUtils::removeNull($configAws);
        ArrayUtils::removeNull($configPath);

        if (!empty($awsConfig)) {
            $awsConfig->update($configAws);
        } else {
            //Making sure service_id is the first item in the config.
            //This way service_id will be set first and is available
            //for use right away. This helps setting an auto-generated
            //field that may depend on parent data. See OAuthConfig->setAttribute.
            $configAws = array_reverse($configAws, true);
            $configAws['service_id'] = $id;
            $configAws = array_reverse($configAws, true);
            AwsConfig::create($configAws);
        }

        if (!empty($pathConfig)) {
            $pathConfig->update($configPath);
        } else {
            //Making sure service_id is the first item in the config.
            //This way service_id will be set first and is available
            //for use right away. This helps setting an auto-generated
            //field that may depend on parent data. See OAuthConfig->setAttribute.
            $configPath = array_reverse($configPath, true);
            $configPath['service_id'] = $id;
            $configPath = array_reverse($configPath, true);
            FilePublicPath::create($configPath);
        }
    }

    /**
     * {@inheritdoc}
     */
    public static function getConfigSchema()
    {
        $out = null;
        $awsConfig = new AwsConfig();
        if (!empty($awsSchema = $awsConfig->getConfigSchema())) {
            $out = $awsSchema;
        }
        $pathConfig = new FilePublicPath();
        if (!empty($pathSchema = $pathConfig->getConfigSchema())) {
            $out = ($out) ? array_merge($out, $pathSchema) : $pathSchema;
        }

        return $out;
    }

    /**
     * {@inheritdoc}
     */
    public static function removeConfig($id)
    {
        // deleting is not necessary here due to cascading on_delete relationship in database
    }

    /**
     * {@inheritdoc}
     */
    public static function getAvailableConfigs()
    {
        return null;
    }
}