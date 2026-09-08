<?php
/**
 * Core Eloquent-style attribute management for models including casting, mutators, and dirty tracking.
 * Handles get/set, serialization, and relation-aware attribute access.
 * The largest model concern powering the active record layer.
 *
 * @package    Framework
 * @subpackage Database\Concerns
 * @since      1.0.0
 */
namespace Framework\Database\Concerns;

defined('ABSPATH') || exit;

use DateTimeInterface;
use Framework\Contracts\Support\Arrayable;
use Framework\Contracts\Support\Jsonable;
use Framework\Database\Connection\Connection;
use Framework\Database\Contracts\CastsAttributes;
use Framework\Database\Query\Relations\Relation;
use Framework\Exceptions\InvalidCastException;
use Framework\Supports\Arr;
use Framework\Supports\Facades\Date;
use ReflectionMethod;
use LogicException;

use function Framework\app;
use function Framework\collection;
use function Framework\Polyfill\str_contains;
use function Framework\tap;
use function Framework\throw_anyway;
use function Framework\throw_if;

trait HasAttributes
{
    /**
     * The model's current attribute values.
     *
     * @var array<string, mixed>
     *
     * @since 1.0.0
     */
    protected $attributes = [];

    /**
     * The original attribute values at the time of model instantiation.
     *
     * @var array<string, mixed>
     *
     * @since 1.0.0
     */
    protected $original = [];

    /**
     * The attributes that have been changed since the last sync.
     *
     * @var array<string, mixed>
     *
     * @since 1.0.0
     */
    protected $changes = [];

    /**
     * The previous attribute values before the last sync.
     *
     * @var array<string, mixed>
     *
     * @since 1.0.0
     */
    protected $previous = [];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $casts = [];

    /**
     * The date format for the model.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $date_format;

    /**
     * The appends attributes to model attributes array
     * 
     * @var array
     * 
     * @since 1.0.0
     */
    protected $appends = [];

    /**
     * The primitive cast types.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $primitive_cast_types = [
        'int',
        'integer',
        'real',
        'float',
        'double',
        'string',
        'bool',
        'boolean',
        'object',
        'array',
        'json',
        'date',
        'datetime',
        'timestamp',
        'unserialize',
        'serialize',
    ];

    /**
     * The cast type cache.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected static $cast_type_cache = [];

    /**
     * Retrieve an attribute or loaded relation value.
     *
     * Returns cast attribute values when present, falls back to loaded
     * relations, then attempts to resolve relation methods dynamically. Null
     * is returned when no value can be resolved.
     *
     * @param string $key The attribute or relation name
     *
     * @return mixed The resolved value or null when absent
     *
     * @since 1.0.0
     */
    public function get_attribute($key)
    {
        if (!$key) {
            return null;
        }

        if ($this->has_attribute($key)) {
            return $this->get_attribute_value($key);
        }

        return $this->is_relation($key) || $this->relation_loaded($key)
            ? $this->get_relation_value($key)
            : null;
    }

    /**
     * Resolve a relation value from a method when available.
     *
     * Attempts to call the relation method and retrieve its results if the
     * method exists, returning null when it does not.
     *
     * @param string $key The relation method name
     *
     * @return mixed The relation results or null
     *
     * @since 1.0.0
     */
    protected function get_relation_value($key)
    {
        if ($this->relation_loaded($key)) {
            return $this->get_relation($key);
        }

        if (!$this->is_relation($key)) {
            return;
        }

        return $this->get_relationship_from_method($key);
    }

    /**
     * Invoke a relation method and store its results on the model.
     *
     * Ensures the returned value is a valid Relation instance, then queries
     * and assigns the results to the relations array under the method name.
     *
     * @param string $method The relation method name to invoke
     *
     * @return mixed The loaded relation results or null when not a relation
     *
     * @since 1.0.0
     */
    protected function get_relationship_from_method($method)
    {
        $relation = $this->$method();

        if (!$relation instanceof Relation) {
            throw_if(is_null($relation), sprintf(
                '%s::%s must return a relationship instance, '
                . 'but "null" was returned. Was the "return" keyword used?',
                static::class,
                $method,
            ), LogicException::class);

            throw_anyway(sprintf(
                '%s::%s must return a relationship instance.',
                static::class,
                $method,
            ), LogicException::class);
        }

        return tap($relation->get_results(), function ($results) use ($method) {
            $this->set_relation($method, $results);
        });
    }

    /**
     * Check if the attribute is a relation.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute is a relation
     *
     * @since 1.0.0
     */
    protected function is_relation($key)
    {
        return array_key_exists($key, $this->relations)
            || method_exists($this, $key);
    }

    /**
     * Relation resolver.
     *
     * @param mixed $model The model instance.
     * @param mixed $key The key.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function relation_resolver($model, $key)
    {
        return false;
    }

    /**
     * Check if the attribute exists.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute exists
     *
     * @since 1.0.0
     */
    protected function has_attribute($key)
    {
        if (!$key) {
            return false;
        }

        return array_key_exists($key, $this->attributes)
            || array_key_exists($key, $this->casts)
            || $this->has_get_mutator($key)
            || $this->is_class_castable($key);
    }

    /**
     * Append attributes from model to its array
     * 
     * @param array $attributes The attributes to append to the array
     *
     * @return $this
     * 
     * @since 1.0.0
     */
    public function append($attributes)
    {
        $this->appends = array_values(
            array_unique(
                array_merge($this->appends, is_string($attributes) ? func_get_args() : $attributes)
            )
        );

        return $this;
    }

    /**
     * Get the appended attributes to the array
     * 
     * @return array
     * 
     * @since 1.0.0
     */
    public function get_appends()
    {
        return $this->appends;
    }

    /**
     * Set the appends accessors to the append on the model array
     * 
     * @param array $appends The accessors to appends
     * 
     * @return $this
     * 
     * @since 1.0.0
     */
    public function set_appends(array $appends)
    {
        $this->appends = $appends;

        return $this;
    }

    /**
     * Merge new appends with the existing ones
     * 
     * @param array $appends The appends to merge
     * 
     * @return $this
     * 
     * @since 1.0.0
     */
    public function merge_appends(array $appends)
    {
        if ($appends === []) {
            return $this;
        }

        $this->appends = array_values(array_unique(array_merge($this->appends, $appends)));

        return $this;
    }

    /**
     * Check if an attribute exists to the appends
     * 
     * @param string $attribute The attribute to check
     * 
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_append($attribute)
    {
        return in_array($attribute, $this->get_appends());
    }

    /**
     * Remove all the appends
     * 
     * @return $this
     * 
     * @since 1.0.0
     */
    public function without_appends()
    {
        return $this->set_appends([]);
    }

    /**
     * Get the attribute value.
     *
     * @param string $key The attribute key
     *
     * @return mixed The attribute value
     *
     * @since 1.0.0
     */
    public function get_attribute_value($key)
    {
        return $this->transform_model_value($key, $this->get_attribute_from_array($key));
    }

    /**
     * Get the attribute value from the array.
     *
     * @param string $key The attribute key
     *
     * @return mixed The attribute value
     *
     * @since 1.0.0
     */
    protected function get_attribute_from_array($key)
    {
        $this->merge_attributes_from_cached_class_casts();

        return $this->attributes[$key] ?? null;
    }

    /**
     * Transform the model value.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to transform
     *
     * @return mixed The transformed value
     *
     * @since 1.0.0
     */
    protected function transform_model_value($key, $value)
    {
        if ($this->has_get_mutator($key)) {
            return $this->mutate_attribute($key, $value);
        }

        if ($this->has_cast($key)) {
            return $this->cast_attribute($key, $value);
        }

        if ($value !== null && in_array($key, $this->get_date_keys(), false)) {
            return $this->as_date_time($value);
        }

        return $value;
    }

    /**
     * Mutate the attribute value.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to mutate
     *
     * @return mixed The mutated value
     *
     * @since 1.0.0
     */
    protected function mutate_attribute($key, $value)
    {
        $this->merge_attributes_from_cached_class_casts();

        $mutator_method = $this->make_get_mutator_method($key);

        return $this->{$mutator_method}($value, $this->attributes);
    }

    /**
     * Get the attributes array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_attributes(): array
    {
        $this->merge_attributes_from_cached_class_casts();

        return $this->attributes;
    }

    /**
     * Get the arrayable attributes
     * 
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    protected function get_arrayable_attributes()
    {
        return $this->get_attributes();
    }

    /**
     * Get the attributes array for insert.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_attributes_for_insert(): array
    {
        return $this->get_attributes();
    }

    /**
     * Set a raw attribute value on the model.
     *
     * Assigns the provided value without casting and returns the model instance
     * for fluent chaining during construction or updates.
     *
     * @param string $key The attribute name to set
     * @param mixed $value The value to assign
     *
     * @return $this The model instance for method chaining
     *
     * @since 1.0.0
     */
    public function set_attribute($key, $value)
    {
        if ($this->has_set_mutator($key)) {
            return $this->set_mutated_attribute_value($key, $value);
        }

        if (!is_null($value) && $this->is_date_attribute($key)) {
            $value = $this->as_date_time($value);
        }

        if ($this->is_class_castable($key)) {
            $this->set_class_castable_attribute($key, $value);

            return $this;
        }

        if (!is_null($value) && $this->is_json_castable($key)) {
            $value = $this->cast_attribute_as_json($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Check if the attribute is json castable.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute is json castable
     *
     * @since 1.0.0
     */
    protected function is_json_castable($key)
    {
        return $this->has_cast($key, ['array', 'json', 'object']);
    }

    /**
     * Cast the attribute as a JSON string.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to cast
     *
     * @return string The JSON string
     *
     * @throws InvalidCastException
     *
     * @since 1.0.0
     */
    protected function cast_attribute_as_json($key, $value)
    {
        $value = $this->as_json($value);

        throw_if($value === false, $this, InvalidCastException::class, $key, 'json');

        return $value;
    }

    /**
     * Convert the value to a JSON string.
     *
     * @param mixed $value The value to convert
     * @param int $flags The flags to use for JSON encoding
     *
     * @return string The JSON string
     *
     * @since 1.0.0
     */
    protected function as_json($value, $flags = 0)
    {
        return Arr::json_encode($value, $flags);
    }

    /**
     * Set a class castable attribute.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to set
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function set_class_castable_attribute($key, $value)
    {
        $caster = $this->resolve_caster_class($key);

        $this->attributes = array_merge(
            $this->attributes,
            [$key => $caster->set($this, $key, $value, $this->attributes)]
        );
    }

    /**
     * Resolve the caster class.
     *
     * @param string $key The attribute key
     *
     * @return mixed The caster class
     *
     * @since 1.0.0
     */
    protected function resolve_caster_class($key)
    {
        $cast_type = $this->get_casts()[$key] ?? null;

        $arguments = [];

        if (is_string($cast_type) && str_contains($cast_type, ':')) {
            $segments = explode(':', $cast_type);

            $cast_type = $segments[0];
            $arguments = explode(',', $segments[1]);
        }

        if (is_subclass_of($cast_type, CastsAttributes::class)) {
            $cast_type = new $cast_type(...$arguments);
        }

        if (is_object($cast_type)) {
            return $cast_type;
        }

        return new $cast_type(...$arguments);
    }

    /**
     * Merge attributes from casts.
     *
     * Merge the attributes from the casts array into the attributes array.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function merge_attributes_from_cached_class_casts()
    {
        // @todo: need to implement this later if we are planning to cache the attribute values.
        // For now this is skipped intentionally to avoid unnecessary overhead.
    }

    /**
     * Check if the attribute has a casted mutator.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute has a cast
     *
     * @since 1.0.0
     */
    protected function has_casts_attributes_class(string $key): bool
    {
        return array_key_exists($key, $this->get_casts()) &&
            $this->get_casts()[$key] instanceof CastsAttributes;
    }


    /**
     * Set raw attributes on the model.
     *
     * Assigns the provided attributes without casting and returns the model instance
     * for fluent chaining during construction or updates.
     *
     * @param array $attributes The attributes to set
     * @param bool $sync Whether to sync the original attributes
     *
     * @return $this The model instance for method chaining
     *
     * @since 1.0.0
     */
    public function set_raw_attributes(array $attributes, $sync = false)
    {
        $this->attributes = $attributes;

        if ($sync) {
            $this->sync_original();
        }

        return $this;
    }

    /**
     * Sync the original attributes with the current attributes.
     *
     * @return static The model instance for method chaining
     *
     * @since 1.0.0
     */
    public function sync_original()
    {
        $this->original = $this->get_attributes();

        return $this;
    }

    /**
     * Sync the changes with the original attributes.
     *
     * @return $this The model instance for method chaining
     *
     * @since 1.0.0
     */
    public function sync_changes()
    {
        $this->changes = $this->get_dirty();
        $this->previous = array_intersect_key($this->get_raw_original(), $this->changes);

        return $this;
    }

    /**
     * Get the raw original attributes.
     *
     * @param string $key The key to get the value from
     * @param mixed $default The default value if the key does not exist
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     *
     * @since 1.0.0
     */
    protected function get_raw_original($key = null, $default = null)
    {
        return Arr::get($this->original, $key, $default);
    }

    /**
     * Get the casts array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_casts()
    {
        return array_merge(
            [$this->get_primary_key() => $this->get_key_type()],
            $this->casts
        );
    }

    /**
     * Merge the given casts with the existing casts.
     *
     * @param array $casts The casts to merge
     *
     * @return $this The model instance for method chaining
     *
     * @since 1.0.0
     */
    public function merge_casts($casts)
    {
        $this->casts = array_merge($this->casts, $casts);

        return $this;
    }

    /**
     * Check if the model has a set mutator for the given attribute.
     *
     * @param string $key The attribute name to check
     *
     * @return bool Whether the model has a set mutator for the attribute
     *
     * @since 1.0.0
     */
    protected function has_set_mutator($key)
    {
        return method_exists($this, $this->make_set_mutator_method($key));
    }

    /**
     * Set a mutated attribute value on the model.
     *
     * @param string $key The attribute name to set
     * @param mixed $value The value to assign
     *
     * @return mixed The set value
     *
     * @since 1.0.0
     */
    protected function set_mutated_attribute_value($key, $value)
    {
        $mutator_method = $this->make_set_mutator_method($key);

        return $this->{$mutator_method}($value, $this->attributes);
    }

    /**
     * Set a casted mutator value on the model.
     *
     * @param string $key The attribute name to set
     * @param mixed $value The value to assign
     *
     * @return mixed The set value
     *
     * @since 1.0.0
     */
    protected function set_casted_mutator_value($key, $value)
    {
        $cast_type = $this->get_cast_type($key);

        if ($cast_type instanceof CastsAttributes) {
            $reflection_method = new ReflectionMethod($cast_type, 'set');

            return $reflection_method->invoke($cast_type, $this, $key, $value, $this->attributes);
        }

        return $value;
    }

    /**
     * Get the mutator method name for setting an attribute.
     *
     * @param string $key The attribute name to get the mutator method for
     *
     * @return string The mutator method name
     *
     * @since 1.0.0
     */
    protected function make_set_mutator_method($key)
    {
        return 'set_' . $key . '_attribute';
    }

    /**
     * Get the mutator method name for getting an attribute.
     *
     * @param string $key The attribute name to get the mutator method for
     *
     * @return string The mutator method name
     *
     * @since 1.0.0
     */
    protected function make_get_mutator_method($key)
    {
        return 'get_' . $key . '_attribute';
    }

    /**
     * Check if the model has a get mutator for the given attribute.
     *
     * @param string $key The attribute name to check
     *
     * @return bool Whether the model has a get mutator for the attribute
     *
     * @since 1.0.0
     */
    protected function has_get_mutator($key)
    {
        return method_exists($this, $this->make_get_mutator_method($key));
    }

    /**
     * Cast a primitive type attribute.
     *
     * @param string $key The attribute name to cast
     * @param mixed $value The value to cast
     *
     * @return mixed The casted value
     *
     * @since 1.0.0
     */
    protected function cast_primitive_type($key, $value)
    {
        $casts = $this->get_casts();

        if (!isset($casts[$key])) {
            return $value;
        }

        $cast_type = $this->get_cast_type($key);

        switch ($cast_type) {
            case 'int':
            case 'integer':
                return (int) $value;
            case 'real':
            case 'float':
            case 'double':
                return (float) $value;
            case 'string':
                return (string) $value;
            case 'bool':
            case 'boolean':
                return (bool) $value;
            case 'object':
                return $this->from_json($value, true);
            case 'array':
            case 'json':
                return is_array($value) ? $value : $this->from_json($value);
            case 'date':
                return $this->as_date($value);
            case 'datetime':
                return $this->as_date_time($value);
            case 'timestamp':
                return $this->as_timestamp($value);
            case 'unserialize':
                return is_serialized($value)
                    ? @unserialize($value, ['allowed_classes' => false])
                    : $value;
            case 'serialize':
                return maybe_serialize($value);
            default:
                return $value;
        }
    }

    /**
     * Cast an attribute from a class.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to cast
     * @param string $method The method to call on the cast type
     *
     * @return mixed The casted value
     *
     * @since 1.0.0
     */
    protected function cast_attribute_from_class($key, $value, $method = 'get')
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        $cast_type = $this->get_cast_type($key);

        if ($cast_type instanceof CastsAttributes) {
            $reflection_method = new ReflectionMethod($cast_type, $method);

            return $reflection_method->invoke($cast_type, $this, $key, $value, $this->attributes);
        }

        return $value;
    }

    /**
     * Cast an attribute to its configured type.
     *
     * Applies casting rules defined on the model to normalize values retrieved
     * from the database into their expected PHP types or structures.
     *
     * @param string $key The attribute name being cast
     * @param mixed $value The raw value to cast
     *
     * @return mixed The casted value according to the model's rules
     *
     * @since 1.0.0
     */
    protected function cast_attribute($key, $value)
    {
        $cast_type = $this->get_cast_type($key);

        if (is_null($value) && in_array($cast_type, static::$primitive_cast_types, true)) {
            return $value;
        }

        if (in_array($cast_type, static::$primitive_cast_types, true)) {
            return $this->cast_primitive_type($key, $value);
        }

        if ($this->is_class_castable($key)) {
            return $this->get_class_castable_attribute_value($key, $value);
        }

        return $value;
    }

    /**
     * Check if the attribute is a class castable attribute.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute is a class castable attribute
     *
     * @throws InvalidCastException
     *
     * @since 1.0.0
     */
    protected function is_class_castable($key)
    {
        $casts = $this->get_casts();

        if (!array_key_exists($key, $casts)) {
            return false;
        }

        $cast_type = $casts[$key];

        if (in_array($cast_type, static::$primitive_cast_types, true)) {
            return false;
        }

        if (class_exists($cast_type)) {
            return true;
        }

        throw_anyway($this, InvalidCastException::class, $key, $cast_type);
    }

    /**
     * Get the class castable attribute value.
     *
     * @param string $key The attribute key
     * @param mixed $value The value to cast
     *
     * @return mixed The casted value
     *
     * @since 1.0.0
     */
    protected function get_class_castable_attribute_value($key, $value)
    {
        $caster = $this->resolve_caster_class($key);

        if ($caster instanceof CastsAttributes) {
            $value = $caster->get($this, $key, $value, $this->attributes);
        }

        return $value;
    }

    /**
     * Get the cast type.
     *
     * @param string $key The cast type
     *
     * @return string|CastsAttributes|null The cast type
     *
     * @since 1.0.0
     */
    protected function get_cast_type($key)
    {
        $cast_type = $this->get_casts()[$key] ?? null;

        if (isset(static::$cast_type_cache[$cast_type])) {
            return static::$cast_type_cache[$cast_type];
        }

        if (is_string($cast_type) && strpos($cast_type, ':') !== false) {
            $converted_cast_type = substr($cast_type, 0, strpos($cast_type, ':'));
        } elseif (class_exists($cast_type)) {
            $converted_cast_type = $cast_type;
        } else {
            $converted_cast_type = trim(strtolower($cast_type));
        }

        return static::$cast_type_cache[$cast_type] = $converted_cast_type;
    }

    /**
     * From json.
     *
     * @param mixed $value The value.
     * @param mixed $as_object The as object.
     *
     * @return void
     *
     * @since 1.0.0
     */
    #[\ReturnTypeWillChange]
    protected function from_json($value, $as_object = false)
    {
        if (empty($value)) {
            return null;
        }

        return json_decode($value, !$as_object);
    }


    /**
     * Convert a value to a date.
     *
     * @param mixed $value The value to convert
     *
     * @return \Framework\Contracts\SomoyInterface The date value
     *
     * @since 1.0.0
     */
    protected function as_date($value)
    {
        return $this->as_date_time($value)->start_of_day();
    }

    /**
     * As date time.
     *
     * @param mixed $value The value.
     *
     * @return \Framework\Contracts\SomoyInterface The date time value
     *
     * @since 1.0.0
     */
    #[\ReturnTypeWillChange]
    protected function as_date_time($value)
    {
        if ($value instanceof DateTimeInterface) {
            return Date::parse(
                $value->format('Y-m-d H:i:s.u'),
                $value->getTimezone()
            );
        }

        if (is_numeric($value)) {
            return Date::create_from_timestamp($value);
        }

        if ($this->is_standard_date_format($value)) {
            return Date::create_from_format('Y-m-d', $value)->start_of_day();
        }

        $format = $this->get_date_format();

        try {
            return Date::create_from_format($format, $value);
        } catch (\Framework\Exceptions\InvalidDateFormatException $exception) {
            return Date::parse($value);
        }
    }

    /**
     * Check if a value is in the standard date format.
     *
     * @param mixed $value The value to check
     *
     * @return bool Whether the value is in the standard date format
     *
     * @since 1.0.0
     */
    protected function is_standard_date_format($value)
    {
        return preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value);
    }

    /**
     * Get the date format for the model.
     *
     * @return string The date format
     *
     * @since 1.0.0
     */
    protected function get_date_format()
    {
        return $this->date_format ?: app()
            ->make(Connection::class)
            ->get_query_compiler()
            ->get_date_format();
    }

    /**
     * Convert a value to a timestamp.
     *
     * @param mixed $value The value to convert
     *
     * @return int The timestamp value
     *
     * @since 1.0.0
     */
    protected function as_timestamp($value)
    {
        return $this->as_date_time($value)->getTimestamp();
    }

    /**
     * Get the attributes that have been modified since last sync.
     *
     * Compares current attributes to the original snapshot and returns a
     * key-value array of changed fields to be persisted during update.
     *
     * @return array The changed attributes keyed by column name
     *
     * @since 1.0.0
     */
    protected function get_dirty()
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!$this->original_is_equivalent($key)) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Check if the original value is equivalent to the current value.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the original value is equivalent to the current value
     *
     * @since 1.0.0
     */
    public function original_is_equivalent($key)
    {
        if (!array_key_exists($key, $this->original)) {
            return false;
        }

        $attribute = $this->attributes[$key] ?? null;
        $original = $this->original[$key] ?? null;

        if ($attribute === $original) {
            return true;
        }

        if (is_null($attribute)) {
            return false;
        }

        if ($this->is_date_attribute($key)) {
            return $this->from_date_time($attribute) === $this->from_date_time($original);
        }

        if ($this->has_cast($key, ['object'])) {
            return $this->from_json($attribute) === $this->from_json($original);
        }

        if ($this->has_cast($key, ['real', 'float', 'double'])) {
            if (is_null($original)) {
                return false;
            }

            return abs(
                $this->cast_attribute($key, $attribute) - $this->cast_attribute($key, $original)
            ) < PHP_FLOAT_EPSILON * 4;
        }

        if (is_numeric($attribute) && is_numeric($original)) {
            return strcmp((string) $attribute, (string) $original) === 0;
        }

        return $attribute === $original;
    }

    /**
     * Check if the attribute is a date attribute.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute is a date attribute
     *
     * @since 1.0.0
     */
    protected function is_date_attribute($key)
    {
        return in_array($key, $this->get_date_keys(), true)
            || $this->is_date_castable($key);
    }

    /**
     * Check if the attribute is a date castable attribute.
     *
     * @param string $key The attribute key
     *
     * @return bool Whether the attribute is a date castable attribute
     *
     * @since 1.0.0
     */
    protected function is_date_castable($key)
    {
        return $this->has_cast($key, ['date', 'datetime']);
    }

    /**
     * Get the date keys for the model.
     *
     * @return array The date keys
     *
     * @since 1.0.0
     */
    protected function get_date_keys()
    {
        return $this->uses_timestamps() ? [
                $this->get_created_at_column(),
                $this->get_updated_at_column(),
            ] : [];
    }

    /**
     * Check if the attribute has a cast.
     *
     * @param string $key The attribute key
     * @param mixed $types The cast types
     *
     * @return bool Whether the attribute has a cast
     *
     * @since 1.0.0
     */
    protected function has_cast($key, $types = null)
    {
        if (array_key_exists($key, $this->get_casts())) {
            return $types ? in_array($this->get_cast_type($key), (array) $types, true) : true;
        }

        return false;
    }

    /**
     * Convert a value to a date time string for storage.
     *
     * @param mixed $value The value to convert
     *
     * @return mixed The formatted date time string or the original empty value
     *
     * @since 1.0.0
     */
    public function from_date_time($value)
    {
        return empty($value)
            ? $value
            : $this->as_date_time($value)->format(
                $this->get_date_format()
            );
    }

    /**
     * Determine whether the model has been persisted.
     *
     * Checks the presence of the primary key in the original snapshot to infer
     * if the instance corresponds to an existing database row.
     *
     * @return bool True when the model exists in the database
     *
     * @since 1.0.0
     */
    protected function exists()
    {
        return isset($this->original[$this->primary_key]);
    }

    /**
     * Determine whether the model has been modified.
     *
     * @param mixed $attributes The attributes to check
     *
     * @return bool True when the model has been modified
     *
     * @since 1.0.0
     */
    public function is_dirty($attributes = null)
    {
        return $this->has_changed(
            $this->get_dirty(),
            is_array($attributes) ? $attributes : func_get_args()
        );
    }

    /**
     * Determine whether the model has not been modified.
     *
     * @param mixed $attributes The attributes to check
     *
     * @return bool True when the model has not been modified
     *
     * @since 1.0.0
     */
    public function is_clean($attributes = null)
    {
        return !$this->is_dirty(...func_get_args());
    }

    /**
     * Summary of has_changed
     *
     * @param mixed $changes The changes.
     * @param mixed $attributes The attributes array.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function has_changed($changes, $attributes = null)
    {
        if (empty($attributes)) {
            return count($changes) > 0;
        }

        return collection(Arr::wrap($attributes))
            ->some(function ($attribute) use ($changes) {
                return array_key_exists($attribute, $changes);
            });
    }

    /**
     * Convert the model's attributes to an array.
     *
     * @return array The attributes array
     *
     * @since 1.0.0
     */
    public function attributes_to_array()
    {
        $attributes = $this->get_attributes();
        $attributes = $this->resolve_date_attributes(
            $attributes = $this->get_arrayable_attributes()
        );

        $attributes = $this->resolve_mutated_attributes(
            $attributes
        );

        $attributes = $this->resolve_casted_attributes(
            $attributes
        );

        foreach ($this->get_appends() as $key) {
            $attributes[$key] = $this->mutate_attribute_for_array($key);
        }

        return $attributes;
    }

    /**
     * Mutate the attribute for the model attribute array
     * 
     * @param string $key The key to add
     * 
     * @return mixed
     * 
     * @since 1.0.0
     */
    protected function mutate_attribute_for_array($key)
    {
        $value = null;

        if ($this->is_class_castable($key)) {
            $value = $this->get_class_castable_attribute_value($key, $value);
        } elseif ($this->has_get_mutator($key)) {
            $value = $this->mutate_attribute($key, $value);
        }

        return $value;
    }

    /**
     * Resolve date attributes.
     *
     * @param array $attributes The attributes to resolve
     *
     * @return array The resolved attributes
     *
     * @since 1.0.0
     */
    protected function resolve_date_attributes(array $attributes)
    {
        foreach ($this->get_date_keys() as $key) {
            if (is_null($key) || !isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->serialize_date(
                $this->as_date_time($attributes[$key])
            );
        }

        return $attributes;
    }

    /**
     * Resolve casted attributes.
     *
     * @param array $attributes The attributes to resolve
     *
     * @return array The resolved attributes
     *
     * @since 1.0.0
     */
    protected function resolve_casted_attributes(array $attributes)
    {
        foreach ($this->get_casts() as $key => $value) {
            // Skip casting if the attribute key is not present in the attributes array.
            // Cast definitions may exist for attributes not loaded in the current instance.
            if (!isset($attributes[$key])) {
                continue;
            }

            $attributes[$key] = $this->cast_attribute($key, $attributes[$key]);

            if (isset($attributes[$key]) && in_array($value, ['date', 'datetime'], true)) {
                $attributes[$key] = $this->serialize_date($attributes[$key]);
            }

            if ($attributes[$key] instanceof DateTimeInterface && $this->is_class_castable($key)) {
                $attributes[$key] = $this->serialize_date($attributes[$key]);
            }

            if ($attributes[$key] instanceof Arrayable) {
                $attributes[$key] = $attributes[$key]->to_array();
            }

            if ($attributes[$key] instanceof Jsonable) {
                $attributes[$key] = $attributes[$key]->to_json();
            }
        }

        return $attributes;
    }

    /**
     * Resolve mutated attributes.
     *
     * @param array $attributes The attributes to resolve
     *
     * @return array The resolved attributes
     *
     * @since 1.0.0
     */
    protected function resolve_mutated_attributes(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            if ($this->has_get_mutator($key)) {
                $attributes[$key] = $this->mutate_attribute($key, $value);
            }
        }

        return $attributes;
    }

    /**
     * Serialize a date.
     *
     * @param DateTimeInterface $date The date to serialize
     *
     * @return string The serialized date
     *
     * @since 1.0.0
     */
    protected function serialize_date(DateTimeInterface $date)
    {
        if ($date instanceof \Framework\Contracts\SomoyInterface) {
            return $date->to_sql_datetime_string();
        }

        return Date::instance($date)->to_sql_datetime_string();
    }

    /**
     * Convert the model's relations to an array.
     *
     * @return array The relations array
     *
     * @since 1.0.0
     */
    public function relations_to_array()
    {
        $attributes = [];

        foreach ($this->relations as $key => $value) {
            if ($value instanceof Arrayable) {
                $relation = $value->to_array();
            } elseif (is_null($value)) {
                $relation = $value;
            }

            if (array_key_exists('relation', get_defined_vars())) {
                $attributes[$key] = $relation ?? null;
            }

            unset($relation);
        }

        return $attributes;
    }
}
