<?php
/**
 * The array helper class.
 *
 * @package    Framework
 * @subpackage Supports
 * @since      1.0.0
 */
namespace Framework\Supports;

defined('ABSPATH') || exit;

use ArrayAccess;
use Closure;
use Error;
use ErrorException;
use Framework\Contracts\Support\Arrayable;
use Framework\Contracts\Support\Jsonable;
use Framework\Collections\Collection;
use Framework\Polyfill\ArgumentCountError;
use InvalidArgumentException;
use JsonSerializable;
use ReflectionFunction;
use ReflectionMethod;

use function Framework\deep_get;
use function Framework\Polyfill\array_all;
use function Framework\Polyfill\array_any;
use function Framework\Polyfill\array_find_key;
use function Framework\Polyfill\array_first;
use function Framework\Polyfill\array_last;
use function Framework\Polyfill\str_contains;
use function Framework\throw_anyway;
use function Framework\value;

class Arr
{
    /**
     * Create an array from the given items.
     *
     * @param mixed $items The items to create an array from
     *
     * @return array
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    public static function from($items)
    {
        switch (true) {
            case is_array($items):
                return $items;
            case $items instanceof Collection:
                return $items->all();
            case $items instanceof Arrayable:
                return $items->to_array();
            case $items instanceof Jsonable:
                return json_decode($items->to_json(), true);
            case $items instanceof JsonSerializable:
                return (array) $items->jsonSerialize();
            case is_object($items):
                return (array) $items;
            default:
                throw_anyway('Items cannot be represented by a scalar value.', InvalidArgumentException::class);
        }
    }

    /**
     * Map the array by a callable function.
     *
     * @param array $array The array to map
     * @param callable $callback The callable function for mapping
     *
     * @return array The mapped array
     *
     * @since 1.0.0
     */
    public static function map(array $array, callable $callback)
    {
        $keys = array_keys($array);

        if (is_array($callback)) {
            $reflector = new ReflectionMethod($callback[0], $callback[1]);
        } elseif (is_string($callback) && strpos($callback, '::') !== false) {
            $reflector = new ReflectionMethod($callback);
        } else {
            $reflector = new ReflectionFunction($callback);
        }

        $required_params = $reflector->getNumberOfRequiredParameters();

        if ($required_params >= 2) {
            $items = array_map($callback, $array, $keys);
        } else {
            $items = array_map($callback, $array);
        }

        return array_combine($keys, $items);
    }

    /**
     * Flatten the array for the sequential array
     *
     * @param array $array The array to flatten
     * @param int|float $depth The maximum depth to flatten
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function flatten($array, $depth = INF)
    {
        $result = [];

        foreach ($array as $item) {
            $item = $item instanceof Collection ? $item->all() : $item;

            if (!is_array($item)) {
                $result[] = $item;
            } else {
                $values = $depth === 1
                    ? array_values($item)
                    : static::flatten($item, $depth - 1);

                foreach ($values as $value) {
                    $result[] = $value;
                }
            }
        }

        return $result;
    }

    /**
     * Filter the array by a callable function.
     * The function will return a true/false value and the return value is true then the value will be kept,
     * otherwise removed.
     *
     * @param array $array The array to filter
     * @param callable $callback The callable function for filtering
     *
     * @return array The filtered array
     *
     * @since 1.0.0
     */
    public static function where($array, callable $callback)
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Reject the array by a callable function.
     * The function will return a true/false value and the return value is false then the value will be rejected,
     * otherwise kept.
     *
     * @param array $array The array to reject
     * @param callable $callback The callable function for rejecting
     *
     * @return array The rejected array
     *
     * @since 1.0.0
     */
    public static function reject($array, callable $callback)
    {
        return static::where($array, function ($value, $key) use ($callback) {
            return !$callback($value, $key);
        });
    }

    /**
     * Accept the array by a callable function.
     * The function will return a true/false value and the return value is true then the value will be accepted,
     * otherwise rejected.
     *
     * @param array $array The array to accept
     * @param callable $callback The callable function for accepting
     *
     * @return array The accepted array
     *
     * @since 1.0.0
     */
    public static function accept($array, callable $callback)
    {
        return static::where($array, function ($value, $key) use ($callback) {
            return $callback($value, $key);
        });
    }

    /**
     * Wrap the value in an array
     *
     * @param mixed $value The value to wrap
     *
     * @return array
     *
     * @since 1.0.0
     */
    public static function wrap($value)
    {
        if (is_null($value)) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Determine whether an array is associative.
     *
     * @param array $array The array to inspect
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is_associative(array $array): bool
    {
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Get a value from an array using a dot notation key.
     *
     * @param array $array The array to get the value from
     * @param string $key The key to get the value from
     * @param mixed $default The default value if the key does not exist
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function get($array, $key, $default = null)
    {
        if (!static::accessible($array)) {
            return value($default);
        }

        if (is_null($key)) {
            return $array;
        }

        if (static::exists($array, $key)) {
            return $array[$key];
        }

        if (!str_contains($key, '.')) {
            return value($default);
        }

        foreach (explode('.', $key) as $segment) {
            if (static::accessible($array) && static::exists($array, $segment)) {
                $array = $array[$segment];
            } else {
                return value($default);
            }
        }

        return $array;
    }

    /**
     * Set a value in an array using a dot notation key.
     *
     * @param array $array The array to set the value in.
     * @param string|array $key The key to set the value in.
     * @param mixed $value The value to set.
     * 
     * @return array
     * 
     * @since 1.0.0
     */
    public static function set(&$array, $key, $value)
    {
        if (is_null($key)) {
            return $array = $value;
        }

        $keys = explode('.', $key);

        foreach ($keys as $index => $key) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$index]);

            if (!isset($array[$key]) || !is_array($array[$key])) {
                $array[$key] = [];
            }

            $array = &$array[$key];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Remove one or many keys from an array using dot notation.
     *
     * @param array $array The array to remove the keys from.
     * @param string|array $keys The key or keys to remove.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public static function forget(&$array, $keys)
    {
        $original = &$array;
        $keys = (array) $keys;

        if (count($keys) === 0) {
            return;
        }

        foreach ($keys as $key) {
            if (static::exists($array, $key)) {
                unset($array[$key]);

                continue;
            }

            $parts = explode('.', $key);
            $array = &$original;

            while (count($parts) > 1) {
                $part = array_shift($parts);

                if (isset($array[$part]) && is_array($array[$part])) {
                    $array = &$array[$part];
                } else {
                    continue 2;
                }
            }

            unset($array[array_shift($parts)]);
        }

        $array = &$original;
    }

    /**
     * Pluck the array by a key
     *
     * @param iterable $array The array to pluck from
     * @param string|array|int|Closure|null $value The value to pluck
     * @param string|array|Closure|null $key The key to pluck by
     *
     * @return array The plucked array
     *
     * @since 1.0.0
     */
    public static function pluck($array, $value, $key = null)
    {
        $results = [];

        foreach ($array as $item) {
            $item_value = $value instanceof Closure
                ? $value($item)
                : deep_get($item, $value);

            if (is_null($key)) {
                $results[] = $item_value;
            } else {
                $item_key = $key instanceof Closure
                    ? $key($item)
                    : deep_get($item, $key);

                if (is_object($item_key) && method_exists($item_key, '__toString')) {
                    $item_key = (string) $item_key;
                }

                $results[$item_key] = $item_value;
            }
        }

        return $results;
    }

    /**
     * Return a new instance containing only the specified keys
     *
     * @param array $items The array to filter
     * @param array $keys The keys to keep
     *
     * @return array A new array containing only the specified keys
     *
     * @since 1.0.0
     */
    public static function only(array $items, array $keys)
    {
        return array_intersect_key($items, array_flip($keys));
    }

    /**
     * Collapse an array of arrays into a single array.
     *
     * @param array $values The array of arrays to collapse
     *
     * @return array The collapsed array
     *
     * @since 1.0.0
     */
    public static function collapse($values)
    {
        $results = [];

        foreach ($values as $item) {
            if ($item instanceof Collection) {
                $results[] = $item->all();
            } elseif (is_array($item)) {
                $results[] = $item;
            }
        }

        return array_merge([], ...$results);
    }

    /**
     * Check if the value is accessible.
     *
     * @param mixed $value The value to check
     *
     * @return boolean True if the value is accessible, false otherwise
     *
     * @since 1.0.0
     */
    public static function accessible($value)
    {
        return is_array($value) || $value instanceof ArrayAccess;
    }

    /**
     * Check if the array has a key.
     *
     * @param array $array The array to check
     * @param mixed $key The key to check for
     *
     * @return boolean True if the array has the key, false otherwise
     *
     * @since 1.0.0
     */
    public static function exists($array, $key)
    {
        if ($array instanceof ArrayAccess) {
            return $array->offsetExists($key);
        }

        if (is_float($key) || is_null($key)) {
            $key = (string) $key;
        }

        return array_key_exists($key, $array);
    }

    /**
     * Get the first item in the array.
     *
     * @param array $array The array to get the first item from
     * @param callable|null $callback The callback to use to find the first item
     * @param mixed|null $default The default value to return if no item is found
     *
     * @return mixed The first item or the default value
     *
     * @since 1.0.0
     */
    public static function first($array, ?callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            if (empty($array)) {
                return value($default);
            }

            if (is_array($array)) {
                return array_first($array);
            }

            foreach ($array as $item) {
                return $item;
            }

            return value($default);
        }

        $array = static::from($array);

        if (is_null($callback)) {
            return !empty($array) ? $array[0] : null;
        }

        $key = array_find_key($array, $callback);

        return $key !== null ? $array[$key] : value($default);
    }

    /**
     * Get the last item in the array.
     *
     * @param array $array The array to get the last item from
     * @param callable|null $callback The callback to use to find the last item
     * @param mixed|null $default The default value to return if no item is found
     *
     * @return mixed The last item or the default value
     *
     * @since 1.0.0
     */
    public static function last($array, ?callable $callback = null, $default = null)
    {
        if (is_null($callback)) {
            return empty($array) ? value($default) : array_last(static::from($array));
        }

        return static::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Determine if any item in the array matches the callback.
     *
     * @param array $array The array to check
     * @param callable $callback The callback to check
     *
     * @return bool True if any item matches the callback, false otherwise
     *
     * @since 1.0.0
     */
    public static function some($array, callable $callback)
    {
        return array_any(static::from($array), $callback);
    }

    /**
     * Determine if all items in the array match the callback.
     *
     * @param array $array The array to check
     * @param callable $callback The callback to check
     *
     * @return bool True if all items match the callback, false otherwise
     *
     * @since 1.0.0
     */
    public static function every($array, callable $callback)
    {
        return array_all(static::from($array), $callback);
    }

    /**
     * Convert the array to a JSON string
     *
     * @param array $array The array to convert
     * @param int $flags The flags to use for JSON encoding
     * @param int $depth The maximum depth to encode
     *
     * @return string The JSON string
     *
     * @since 1.0.0
     */
    public static function json_encode($array, $flags = 0, $depth = 512)
    {
        return wp_json_encode($array, $flags, $depth);
    }

    /**
     * Join the array items into a string.
     *
     * @param array $array The array to join
     * @param string $separator The separator
     *
     * @return string The joined string
     *
     * @since 1.0.0
     */
    public static function join(array $array, string $separator = ',')
    {
        return implode($separator, $array);
    }
}
