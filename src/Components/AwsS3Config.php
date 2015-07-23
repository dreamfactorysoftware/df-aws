<?php
namespace DreamFactory\Core\Aws\Components;

use DreamFactory\Core\Aws\Models\AwsConfig;
use DreamFactory\Core\Components\FileServiceWithContainer;
use DreamFactory\Core\Contracts\ServiceConfigHandlerInterface;
use DreamFactory\Core\Models\FilePublicPath;
use DreamFactory\Library\Utility\ArrayUtils;

class AwsS3Config implements ServiceConfigHandlerInterface
{
    use FileServiceWithContainer;

    /**
     * @param int $id
     *
     * @return array
     */
    public static function getConfig($id)
    {
        $awsConfig = AwsConfig::find($id);
        $pathConfig = FilePublicPath::find($id);

        $config = [];

        if (!empty($awsConfig)) {
            $config = $awsConfig->toArray();
        }

        if (!empty($pathConfig)) {
            $config = array_merge($config, $pathConfig->toArray());
        }

        return $config;
    }

    /**
     * {@inheritdoc}
     */
    public static function validateConfig($config, $create=true)
    {
        return (AwsConfig::validateConfig($config, $create) && FilePublicPath::validateConfig($config, $create));
    }

    /**
     * {@inheritdoc}
     */
    public static function setConfig($id, $config)
    {
        $awsConfig = AwsConfig::find($id);
        $pathConfig = FilePublicPath::find($id);
        $configPath = [
            'public_path' => ArrayUtils::get($config, 'public_path'),
            'container'   => ArrayUtils::get($config, 'container')
        ];
        $configAws = [
            'service_id' => ArrayUtils::get($config, 'service_id'),
            'key'        => ArrayUtils::get($config, 'key'),
            'secret'     => ArrayUtils::get($config, 'secret'),
            'region'     => ArrayUtils::get($config, 'region')
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
        $awsConfig = new AwsConfig();
        $pathConfig = new FilePublicPath();
        $out = null;

        $awsSchema = $awsConfig->getConfigSchema();
        $pathSchema = $pathConfig->getConfigSchema();

        static::updatePathSchema($pathSchema);

        if (!empty($awsSchema)) {
            $out = $awsSchema;
        }
        if (!empty($pathSchema)) {
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