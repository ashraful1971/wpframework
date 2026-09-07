<?php
/**
 * Trait to enumerate values of the collection.
 *
 * @package    Framework
 * @subpackage Collections\Concerns
 * @since      1.0.0
 */
namespace Framework\Collections\Concerns;

defined('ABSPATH') || exit;

use Exception;
use Framework\Collections\HigherOrderCollectionProxy;

use function Framework\deep_get;
use function Framework\throw_unless;

/**
 * Trait to enumerate values of the collection.
 *
 * @template TKey of array-key
 * @template TValue
 *
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $average
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $avg
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $contains
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $each
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $every
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $filter
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $first
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $flat_map
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $group_by
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $key_by
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $last
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $map
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $max
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $min
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $reject
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $some
 * @property-read HigherOrderCollectionProxy<TKey, TValue> $sum
 */

trait EnumeratesValues
{
    /**
     * The proxies.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $proxies = [
        'average',
        'avg',
        'contains',
        'each',
        'every',
        'filter',
        'first',
        'flat_map',
        'group_by',
        'key_by',
        'last',
        'map',
        'max',
        'min',
        'reject',
        'some',
        'sum',
    ];
    /**
     * Determine if the value is callable.
     *
     * @param mixed $value The value to check
     *
     * @return bool True when callable; false otherwise
     *
     * @since 1.0.0
     */
    protected function is_callable($value)
    {
        return !is_string($value) && is_callable($value);
    }

    /**
     * Get the value of the item.
     *
     * @param callable|string|null $value The value to get
     *
     * @return callable The value of the item
     *
     * @since 1.0.0
     */
    protected function value_retriever($value)
    {
        if ($this->is_callable($value)) {
            return $value;
        }

        return fn ($item) => deep_get($item, $value);
    }

    /**
     * Get the value of the property.
     *
     * @param string $key The key of the property.
     *
     * @return mixed
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function __get($key)
    {
        throw_unless(
            in_array($key, static::$proxies, true),
            sprintf('Property [%s] does not exist on this collection.', $key),
            Exception::class
        );

        return new HigherOrderCollectionProxy($this, $key);
    }
}
