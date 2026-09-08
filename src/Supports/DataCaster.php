<?php
/**
 * Utility for casting values and nested structures to scalar and collection types.
 * Normalizes request, option, and model data before validation and persistence layers consume it.
 * Keeps type coercion logic out of controllers and repositories.
 *
 * @package    Framework
 * @subpackage Supports
 * @since      1.0.0
 */
namespace Framework\Supports;

defined('ABSPATH') || exit;

use function Framework\throw_if;

class DataCaster
{
    /**
     * Cast a value to the given type.
     *
     * @param mixed $value The value.
     * @param mixed $type The type.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public static function cast_value($value, $type = null)
    {
        if (is_null($type)) {
            return $value;
        }

        switch (strtolower($type)) {
            case 'int':
            case 'integer':
                return (int) $value;

            case 'float':
            case 'double':
                return (float) $value;

            case 'bool':
            case 'boolean':
                return (bool) $value;

            case 'string':
                return (string) $value;

            case 'array':
                if (is_array($value)) {
                    return $value;
                }

                if (is_string($value)) {
                    $decoded = json_decode($value, true);

                    if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                        return $decoded;
                    }

                    return [$value];
                }

                // For anything else (int, bool, object, etc.) cast to array directly
                return [$value];

            default:
                return $value;
        }
    }

    /**
     * Cast an array or object using a type map.
     *
     * @param mixed $data The data payload.
     * @param mixed $map The map.
     *
     * @return array
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public static function cast_data($data, $map)
    {
        throw_if(!is_array($data) && !is_object($data), 'Data must be either an array or an object');

        if (is_object($data)) {
            foreach ($map as $key => $type) {
                $data->{$key} = static::cast_value($data->{$key}, $type);
            }
        }

        if (is_array($data)) {
            foreach ($map as $key => $type) {
                $data[$key] = static::cast_value($data[$key], $type);
            }
        }

        return $data;
    }
}
