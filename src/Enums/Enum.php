<?php
namespace DreamFactory\Core\Aws\Enums;

/**
 * Represents an enumerable set of values
 */
abstract class Enum
{
    /**
     * @var array A cache of all enum values to increase performance
     */
    protected static $cache = array();

    /**
     * Returns the names (or keys) of all of constants in the enum
     *
     * @return array
     */
    public static function keys()
    {
        return array_keys(static::values());
    }

    /**
     * Return the names and values of all the constants in the enum
     *
     * @return array
     */
    public static function values()
    {
        $class = get_called_class();

        if (!isset(self::$cache[$class])) {
            $reflected = new \ReflectionClass($class);
            self::$cache[$class] = $reflected->getConstants();
        }

        return self::$cache[$class];
    }
}
