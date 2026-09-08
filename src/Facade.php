<?php
/**
 * Abstract base for static facades that proxy calls to container-resolved services.
 * Caches resolved instances per accessor and forwards __callStatic to the underlying object.
 * Enables Facade::method() syntax throughout the framework.
 *
 * @package Framework
 * @since   1.0.0
 */
namespace Framework;

defined('ABSPATH') || exit;

use RuntimeException;

use function Framework\app;
use function Framework\throw_if;

abstract class Facade
{
    /**
     * The array of resolved service instances keyed by accessor name.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $resolved_instance = [];

    /**
     * Whether the facade is cacheable.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected static $is_cacheable = true;

    /**
     * Get the accessor key for the service in the container.
     *
     * Child classes must implement this method to return the string accessor
     * used to resolve the service from the application container.
     *
     * @return string
     *
     * @since 1.0.0
     */
    abstract public static function get_accessor();

    /**
     * Resolve the underlying service instance for the given accessor name.
     *
     * If the name is already an object, it is returned directly. Otherwise,
     * the service is resolved from the application container and cached.
     *
     * @param string|object $name The accessor name or an object instance
     *
     * @return object The resolved service instance
     *
     * @since 1.0.0
     */
    protected static function resolved_facade_instance($name)
    {
        if (is_object($name)) {
            return $name;
        }

        if (static::$is_cacheable && isset(static::$resolved_instance[$name])) {
            return static::$resolved_instance[$name];
        }

        $instance = app($name);

        if (static::$is_cacheable) {
            static::$resolved_instance[$name] = $instance;
        }

        return $instance;
    }

    /**
     * Dynamically handle static method calls to the facade.
     *
     * Forwards the static call to the resolved service instance, passing all arguments.
     * Throws a RuntimeException if the service instance cannot be resolved.
     *
     * @param string $method The method name being called
     * @param array $arguments The arguments passed to the method
     *
     * @return mixed The result of the underlying method call
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    public static function __callStatic($method, $arguments)
    {
        $instance = static::resolved_facade_instance(static::get_accessor());

        throw_if(!$instance, 'A facade has not been set.', RuntimeException::class);

        return $instance->$method(...$arguments);
    }
}
