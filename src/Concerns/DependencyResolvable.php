<?php
/**
 * Trait that resolves method parameters via reflection and the application container.
 * Injects typed class dependencies automatically and fills primitive arguments from a provided map or defaults.
 * Powers controller and route action resolution without manual wiring.
 *
 * @package    Framework
 * @subpackage Concerns
 * @since      1.0.0
 */
namespace Framework\Concerns;

defined('ABSPATH') || exit;

use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function Framework\app;
use function Framework\throw_anyway;

trait DependencyResolvable
{
    /**
     * Resolve primitive.
     *
     * @param ReflectionParameter $parameter The parameter.
     *
     * @return mixed
     *
     * @throws \ReflectionException
     *
     * @since 1.0.0
     */
    protected function resolve_primitive(ReflectionParameter $parameter)
    {
        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        throw_anyway(sprintf(
            'Unable to resolve primitive parameter "%s" in class "%s".',
            $parameter->getName(),
            $parameter->getDeclaringClass()->getName()
        ), ReflectionException::class);
    }

    /**
     * Resolve the method dependencies.
     *
     * @param mixed $class The class.
     * @param string $method The method name.
     * @param array $arguments The arguments.
     *
     * @return array
     * 
     * @since 1.0.0
     */
    protected function resolve_method_dependencies($class, string $method, array $arguments = [])
    {
        $dependencies = [];
        $reflector = new ReflectionMethod($class, $method);

        foreach ($reflector->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type === null || $type->isBuiltin()) {
                $dependencies[] = $this->resolve_primitive($parameter);
                continue;
            }

            $type_name = $type instanceof ReflectionNamedType
                ? $type->getName()
                : (string) $type;

            $argument = $this->pick($arguments, $type_name);

            if (is_object($argument) && is_a($argument, $type_name)) {
                $dependencies[] = $argument;
                continue;
            }

            if (is_string($argument) && $argument === $type_name) {
                $dependencies[] = app()->make($argument);
            }
        }

        return $dependencies;
    }

    /**
     * Pick the argument from the arguments array.
     *
     * @param array $arguments The arguments array.
     * @param string $type_name The type name.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function pick(array $arguments, string $type_name)
    {
        foreach ($arguments as $argument) {
            $name = is_object($argument)
                ? get_class($argument)
                : $argument;

            if ($name === $type_name) {
                return $argument;
            }
        }

        return null;
    }
}
