<?php
/**
 * Trait defining has_one, has_many, belongs_to, and belongs_to_many relationship builders.
 * Infers foreign and local keys from model conventions when omitted.
 * Entry point for defining associations on Model subclasses.
 *
 * @package    Framework
 * @subpackage Database\Concerns
 * @since      1.0.0
 */
namespace Framework\Database\Concerns;

defined('ABSPATH') || exit;

use Framework\Database\Query\Relations\BelongsTo;
use Framework\Database\Query\Relations\BelongsToMany;
use Framework\Database\Query\Relations\HasMany;
use Framework\Database\Query\Relations\HasOne;
use Framework\Supports\Arr;

trait HasRelationships
{
    /**
     * Define a one-to-one relationship.
     *
     * Creates a HasOne relation object targeting the given model class. Keys
     * default to the parent's foreign key and primary key when omitted.
     *
     * @param string $related The related model class name
     * @param mixed $foreign_key The foreign key name on the related model
     * @param mixed $local_key The local key name on the parent model
     *
     * @return HasOne The relation instance
     *
     * @since 1.0.0
     */
    protected function has_one($related, $foreign_key = null, $local_key = null)
    {
        $instance = new $related();
        $foreign_key = $foreign_key ?? $this->get_foreign_key();
        $local_key = $local_key ?? $this->primary_key;

        return new HasOne($instance, $this, $foreign_key, $local_key);
    }

    /**
     * Define a one-to-many relationship.
     *
     * Creates a HasMany relation object targeting the given model class. Keys
     * default similarly to has_one when not explicitly specified.
     *
     * @param string $related The related model class name
     * @param mixed $foreign_key The foreign key name on the related model
     * @param mixed $local_key The local key name on the parent model
     *
     * @return HasMany The relation instance
     *
     * @since 1.0.0
     */
    protected function has_many($related, $foreign_key = null, $local_key = null)
    {
        $instance = new $related();
        $foreign_key = $foreign_key ?? $this->get_foreign_key();
        $local_key = $local_key ?? $this->primary_key;

        return new HasMany($instance, $this, $foreign_key, $local_key);
    }

    /**
     * Define an inverse one-to-one or many relationship.
     *
     * Creates a BelongsTo relation where this model holds the foreign key
     * referencing the owner model's primary key (or provided owner key).
     *
     * @param string $related The related model class name
     * @param mixed $foreign_key The foreign key name on this model
     * @param mixed $owner_key The referenced key name on the related model
     * @param string $relation The name of the relation
     *
     * @return BelongsTo The relation instance
     *
     * @since 1.0.0
     */
    protected function belongs_to($related, $foreign_key = null, $owner_key = null, $relation = null)
    {
        $instance = new $related();
        $foreign_key = $foreign_key ?? $instance->get_foreign_key();
        $owner_key = $owner_key ?? $instance->primary_key;
        $relation = $relation ?? $this->guess_belongs_to_relation();

        return new BelongsTo(
            $instance,
            $this,
            $foreign_key,
            $owner_key,
            $relation
        );
    }

    /**
     * Guess the name of the relation.
     *
     * @return string The name of the relation
     *
     * @since 1.0.0
     */
    protected function guess_belongs_to_relation()
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Introspects the calling method name to guess a relation; not debugging output.
        [, , $caller] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);

        return $caller['function'];
    }

    /**
     * Define a many-to-many relationship using a pivot table.
     *
     * Constructs a BelongsToMany relation with optional pivot table and key
     * names. When the pivot table is not given, it is inferred by sorting and
     * joining the table names. Parent and related keys default to primary keys.
     *
     * @param string $related The related model class name
     * @param mixed $pivot_table The pivot table name joining the models
     * @param mixed $foreign_pivot_key The foreign key for this model on pivot
     * @param mixed $related_pivot_key The foreign key for related on pivot
     * @param mixed $parent_key The local key on this model
     * @param mixed $related_key The key on the related model
     *
     * @return BelongsToMany The relation instance
     *
     * @since 1.0.0
     */
    protected function belongs_to_many(
        $related,
        $pivot_table = null,
        $foreign_pivot_key = null,
        $related_pivot_key = null,
        $parent_key = null,
        $related_key = null,
        $relation = null
    ) {
        $instance = new $related();

        $foreign_pivot_key = $foreign_pivot_key ?: $this->get_foreign_key();
        $related_pivot_key = $related_pivot_key ?: $instance->get_foreign_key();

        $parent_key = $parent_key ?: $this->get_primary_key();
        $related_key = $related_key ?: $instance->get_primary_key();

        if ($pivot_table === null) {
            $pivot_table = $this->get_pivot_table_name($this->get_table(), $instance->get_table());
        }

        $relation = $relation ?? $this->guess_belongs_to_many_relation();

        return new BelongsToMany(
            $instance,
            $this,
            $pivot_table,
            $foreign_pivot_key,
            $related_pivot_key,
            $parent_key,
            $related_key,
            $relation
        );
    }

    /**
     * Guess the name of the relation.
     *
     * @return string The name of the relation
     *
     * @since 1.0.0
     */
    protected function guess_belongs_to_many_relation()
    {
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace -- Introspects the calling method name to guess a relation; not debugging output.
        $caller = Arr::first(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS), function ($trace) {
            return !in_array(
                $trace['function'],
                ['belongs_to_many', 'guess_belongs_to_many_relation'],
                true
            );
        });

        return $caller['function'] ?? null;
    }

    /**
     * Set a relation on the model.
     *
     * @param string $relation The relation name
     * @param mixed $value The value to set
     *
     * @return $this The model instance
     *
     * @since 1.0.0
     */
    public function set_relation($relation, $value)
    {
        $this->relations[$relation] = $value;

        return $this;
    }

    /**
     * Check if a relation is loaded.
     *
     * @param string $key The relation name
     *
     * @return bool Whether the relation is loaded
     *
     * @since 1.0.0
     */
    public function relation_loaded($key)
    {
        return array_key_exists($key, $this->relations);
    }

    /**
     * Get all relations.
     *
     * @return mixed The relation value
     *
     * @since 1.0.0
     */
    public function get_relations()
    {
        return $this->relations;
    }

    /**
     * Get a relation.
     *
     * @param string $relation The relation name
     *
     * @return mixed The relation value
     *
     * @since 1.0.0
     */
    public function get_relation($relation)
    {
        return $this->relations[$relation];
    }

    /**
     * Unset all relations.
     *
     * @return $this The model instance
     *
     * @since 1.0.0
     */
    public function unset_relations()
    {
        $this->relations = [];

        return $this;
    }

    /**
     * Unset a relation by relation name.
     *
     * @param string $relation The relation name
     *
     * @return $this The model instance
     *
     * @since 1.0.0
     */
    public function unset_relation($relation)
    {
        unset($this->relations[$relation]);

        return $this;
    }

    /**
     * Create a new model instance without any relations.
     *
     * @return $this The model instance
     *
     * @since 1.0.0
     */
    public function without_relations()
    {
        $model = clone $this;

        return $model->unset_relations();
    }

    /**
     * Create a new model instance without a specific relation.
     *
     * @param string $relation The relation name
     *
     * @return $this The model instance
     *
     * @since 1.0.0
     */
    public function without_relation($relation)
    {
        $model = clone $this;

        return $model->unset_relation($relation);
    }
}
