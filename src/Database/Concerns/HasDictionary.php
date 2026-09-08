<?php
/**
 * Trait providing dictionary key normalization for model collections.
 * Converts attribute keys to strings via __toString or casting, throwing on unsupported types.
 * Ensures collection merge and lookup use consistent primary-key indexing.
 *
 * @package    Framework
 * @subpackage Database\Concerns
 * @since      1.0.0
 */
namespace Framework\Database\Concerns;

defined('ABSPATH') || exit;

use InvalidArgumentException;

use function Framework\throw_anyway;

trait HasDictionary
{
    /**
     * Get the dictionary key for the attribute.
     *
     * @param mixed $attribute The attribute to get the key for
     *
     * @return string The dictionary key
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    protected function get_dictionary_key($attribute)
    {
        if (is_null($attribute) || is_string($attribute) || is_int($attribute)) {
            return $attribute;
        }

        if (is_object($attribute)) {
            if (method_exists($attribute, '__toString')) {
                return $attribute->__toString();
            }

            throw_anyway(
                'Attribute must be a string, integer, or object with a __toString method.',
                InvalidArgumentException::class
            );
        }

        return (string) $attribute;
    }
}
