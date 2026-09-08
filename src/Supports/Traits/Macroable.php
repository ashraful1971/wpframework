<?php
/**
 * Trait allowing runtime registration of custom static and instance methods via macro.
 * Stores callables in a static map and dispatches unknown methods to them.
 * Used by Application, Filesystem, and HTTP Request for extensibility.
 *
 * @package    Framework
 * @subpackage Supports\Traits
 * @since      1.0.0
 */
namespace Framework\Supports\Traits;

defined('ABSPATH') || exit;

use BadMethodCallException;

use function Framework\throw_anyway;

trait Macroable
{
    /**
     * The macros.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static array $macros = [];

    /**
     * Register a macro.
     *
     * @param mixed $name The name.
     * @param callable $macro The macro.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function macro($name, callable $macro)
    {
        static::$macros[$name] = $macro;
    }

    /**
     * Check if a macro is registered.
     *
     * @param mixed $name The name.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function has_macro($name)
    {
        return isset(static::$macros[$name]);
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param string $method The method name.
     * @param array $arguments The method arguments.
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     *
     * @since 1.0.0
     */
    public function __call(string $method, array $arguments)
    {
        if (isset(static::$macros[$method])) {
            return call_user_func_array(
                static::$macros[$method]->bindTo($this, static::class),
                $arguments
            );
        }

        throw_anyway(
            sprintf('Method %s::%s does not exist.', static::class, esc_html($method)),
            BadMethodCallException::class
        );
    }

    /**
     * Dynamically handle calls to the class.
     *
     * @param string $method The method name.
     * @param array $arguments The method arguments.
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     *
     * @since 1.0.0
     */
    public static function __callStatic(string $method, array $arguments)
    {
        if (isset(static::$macros[$method])) {
            return call_user_func_array(
                static::$macros[$method]->bindTo(null, static::class),
                $arguments
            );
        }

        throw_anyway(
            sprintf('Method %s::%s does not exist.', static::class, esc_html($method)),
            BadMethodCallException::class
        );
    }
}
