<?php
/**
 * Base class for ORM relationship implementations.
 * Holds references to the parent and related models and a query builder used to retrieve related data.
 * Concrete relations implement constraint and match logic for lazy and eager loading scenarios.
 *
 * @package    Framework
 * @subpackage Database\Query\Relations
 * @since      1.0.0
 */
namespace Framework\Database\Query\Relations;

defined('ABSPATH') || exit;

use BadMethodCallException;
use Closure;
use Framework\Database\Query\Collection;
use Framework\Database\Query\Expression;
use Framework\Database\Query\Model;
use Framework\Database\Query\QueryBuilder;
use Framework\Collections\Collection as BaseCollection;

use function Framework\throw_if;

abstract class Relation
{
    /**
     * The related model instance for the relationship.
     *
     * @var Model
     *
     * @since 1.0.0
     */
    protected $related;

    /**
     * The parent model instance holding the relationship.
     *
     * @var Model
     *
     * @since 1.0.0
     */
    protected $parent;

    /**
     * The query builder instance for retrieving related data.
     *
     * @var QueryBuilder
     *
     * @since 1.0.0
     */
    protected $query;

    /**
     * Whether to apply constraints to the relation query.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected static $constraints = true;

    /**
     * The count of self joins.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected static $self_join_count = 0;

    /**
     * Whether the eager keys were empty.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $eager_keys_were_empty = false;

    /**
     * Construct a new relation for the given models.
     *
     * Initializes the relation context and prepares a query builder targeting
     * the related model's table. Concrete implementations will add specific
     * constraints appropriate for the relationship type.
     *
     * @param Model $related The related model instance
     * @param Model $parent The parent model holding the relation
     *
     * @return void No return value
     *
     * @since 1.0.0
     */
    public function __construct(Model $related, Model $parent)
    {
        $this->related = $related;
        $this->parent = $parent;
        $this->query = $related::query();

        $this->add_constraints();
    }

    /**
     * Apply base constraints to the relation query.
     *
     * Concrete relations should limit the query based on the relation keys so
     * that fetching returns only records related to the parent context.
     *
     * @return void
     *
     * @since 1.0.0
     */
    abstract public function add_constraints();

    /**
     * Retrieve the relation results for lazy loading.
     *
     * Should return a single model or collection depending on the relation
     * type. Used when accessing the relation as a property on the parent model.
     *
     * @return mixed The relation result set
     *
     * @since 1.0.0
     */
    abstract public function get_results();

    /**
     * Add constraints for eager loading across multiple parents.
     *
     * Accepts the array of parent models and scopes the query to fetch all
     * related records needed in a single query using where-in style logic.
     *
     * @param Collection $models The parent models to constrain by
     *
     * @return void
     *
     * @since 1.0.0
     */
    abstract public function add_eager_constraints(Collection $models);

    /**
     * Get the eager results.
     *
     * @return Collection The eager results
     *
     * @since 1.0.0
     */
    public function get_eager()
    {
        return $this->eager_keys_were_empty
            ? $this->related->new_collection()
            : $this->get();
    }

    /**
     * Where in eager.
     *
     * @param string $key The key.
     * @param array $model_keys The model keys.
     * @param QueryBuilder $query The query.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    protected function where_in_eager(string $key, array $model_keys, ?QueryBuilder $query = null)
    {
        $query = $query ?? $this->query;
        $query->where_in($key, $model_keys);

        if ($model_keys === []) {
            $this->eager_keys_were_empty = true;
        }
    }

    /**
     * Get the aggregate query for the relation.
     *
     * Accepts the query builder for the relation and the parent model and
     * returns the aggregate query for the relation.
     *
     * @param QueryBuilder $query The query builder for the relation
     * @param QueryBuilder $parent The parent model
     * @param mixed $columns The columns to select
     *
     * @return QueryBuilder The aggregate query
     *
     * @since 1.0.0
     */
    public function get_relation_existence_query(QueryBuilder $query, QueryBuilder $parent, $columns = ['*'])
    {
        return $query->select($columns)->where_column(
            $this->get_qualified_parent_key_name(),
            '=',
            $this->get_existence_compare_key()
        );
    }

    /**
     * Get the qualified parent key name for the relation.
     *
     * @return string The qualified parent key name
     *
     * @since 1.0.0
     */
    public function get_qualified_parent_key_name()
    {
        return $this->parent->get_prepared_key_name();
    }

    /**
     * Summary of get_relation_existence_count_query
     *
     * @param QueryBuilder $query The query builder instance.
     * @param QueryBuilder $parent The parent.
     *
     * @return QueryBuilder
     *
     * @since 1.0.0
     */
    public function get_relation_existence_count_query(QueryBuilder $query, QueryBuilder $parent)
    {
        return $this->get_relation_existence_query(
            $query,
            $parent,
            new Expression('count(*)')
        )->set_bindings([], 'select');
    }

    /**
     * Initialize the relation.
     *
     * @param Collection $models The models.
     * @param string $relation The relation.
     *
     * @return Collection The initialized relation.
     * 
     * @since 1.0.0
     */
    abstract public function init_relation(Collection $models, $relation);

    /**
     * Match eager loaded results back onto their parent models.
     *
     * Builds a dictionary keyed by relation keys and assigns the results to
     * the appropriate relation property on each model.
     *
     * @param Collection $models The parent models receiving results
     * @param mixed $results The retrieved related results
     * @param string $relation The relation name on the parent
     *
     * @return array The parent models with relations populated
     *
     * @since 1.0.0
     */
    abstract public function match(Collection $models, Collection $results, $relation);

    /**
     * Execute the relation query and return a collection.
     *
     * Delegates to the underlying query builder get method. Used by concrete
     * relations to return multiple results.
     *
     * @param mixed $columns The columns.
     *
     * @return Collection A collection of related models
     *
     * @since 1.0.0
     */
    public function get($columns = ['*'])
    {
        return $this->query->get($columns);
    }

    /**
     * Execute the relation query and return the first result.
     *
     * Delegates to the query builder first method. Used by relations that
     * return a single related model instance.
     *
     * @param mixed $columns The columns.
     *
     * @return mixed The first related model or null when none
     *
     * @since 1.0.0
     */
    public function first($columns = ['*'])
    {
        return $this->query->first($columns);
    }

    /**
     * Add a where clause to the relation query.
     *
     * Forwards the condition to the underlying query builder and returns the
     * relation for chaining additional constraints in a fluent style.
     *
     * @param string $column The column to compare
     * @param mixed $operator The comparison operator or value when two args
     * @param mixed $value The value to compare against when operator provided
     * @param string $boolean The boolean operator to use
     *
     * @return $this The relation instance for method chaining
     *
     * @since 1.0.0
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and')
    {
        $this->query->where($column, $operator, $value, $boolean);
        return $this;
    }

    /**
     * Add a where in clause to the relation query.
     *
     * Filters by membership in the provided values and returns the relation to
     * support fluent chaining of additional constraints.
     *
     * @param string $column The column to filter
     * @param array $values The list of allowed values
     * @param mixed $boolean The boolean.
     * @param mixed $not The not.
     *
     * @return $this The relation instance for method chaining
     *
     * @since 1.0.0
     */
    public function where_in($column, array $values, $boolean = 'and', $not = false)
    {
        $this->query->where_in($column, $values, $boolean, $not);
        return $this;
    }

    /**
     * Apply an order by clause to the relation query.
     *
     * Sets the sort column and direction (default ASC) and returns the
     * relation to allow further chained modifications.
     *
     * @param string $column The column to sort by
     * @param string $direction The direction, ASC or DESC
     *
     * @return $this The relation instance for method chaining
     *
     * @since 1.0.0
     */
    public function order_by($column, $direction = 'ASC')
    {
        $this->query->order_by($column, $direction);
        return $this;
    }

    /**
     * Limit the maximum number of records returned.
     *
     * Forwards to the underlying query builder and returns the relation for
     * fluent chaining behavior.
     *
     * @param int $limit The maximum number of records to return
     *
     * @return $this The relation instance for method chaining
     *
     * @since 1.0.0
     */
    public function limit($limit)
    {
        $this->query->limit($limit);
        return $this;
    }

    /**
     * Offset the starting position for returned records.
     *
     * Useful in conjunction with limit for paginating related results. Returns
     * the relation instance to continue chaining.
     *
     * @param int $offset The number of records to skip
     *
     * @return $this The relation instance for method chaining
     *
     * @since 1.0.0
     */
    public function offset($offset)
    {
        $this->query->offset($offset);
        return $this;
    }

    /**
     * Get the underlying query builder for the relation.
     *
     * Exposes the builder when direct access is necessary for advanced use
     * cases beyond the convenience methods provided on the relation.
     *
     * @return QueryBuilder The relation's query builder instance
     *
     * @since 1.0.0
     */
    public function get_query()
    {
        return $this->query;
    }

    /**
     * Get the related model instance.
     *
     * Provides access to the related model prototype used by the relation for
     * constructing queries and hydrating results.
     *
     * @return Model The related model instance
     *
     * @since 1.0.0
     */
    public function get_related()
    {
        return $this->related;
    }

    /**
     * Get the parent model instance.
     *
     * Returns the model on which this relation is defined. Useful when
     * constructing custom constraints or accessing parent keys.
     *
     * @return Model The parent model instance
     *
     * @since 1.0.0
     */
    public function get_parent()
    {
        return $this->parent;
    }

    /**
     * Get the local key for the relation.
     *
     * Returns the key on the parent model that is used to match related records.
     * This is used by with_count() to match count results back to parent models.
     *
     * @return string The local key name
     *
     * @since 1.0.0
     */
    abstract public function get_local_key();

    /**
     * Get a hash for the relation count query.
     *
     * @param bool $increment_join_count Whether to increment the join count
     *
     * @return string The hash for the relation count query
     *
     * @since 1.0.0
     */
    public function get_relation_count_hash($increment_join_count = true)
    {
        return 'framework_reserved_' . (
            $increment_join_count
                ? static::$self_join_count++
                : static::$self_join_count
        );
    }

    /**
     * Get the keys from the models.
     *
     * @param Collection $models The models.
     * @param string $key The key.
     *
     * @return array The keys.
     *
     * @since 1.0.0
     */
    protected function get_keys(Collection $models, $key = null)
    {
        return (new BaseCollection($models))->map(function ($model) use ($key) {
            return $key ? $model->get_attribute($key) : $model->get_primary_key_value();
        })->values()->unique(null, true)->all();
    }

    /**
     * Get the relation query.
     *
     * @return QueryBuilder The relation query.
     *
     * @since 1.0.0
     */
    protected function get_relation_query()
    {
        return $this->query;
    }

    /**
     * Qualify a column name with the related table name.
     *
     * @param string $key The column name to qualify
     *
     * @return string The qualified column name
     *
     * @since 1.0.0
     */
    public function qualify_column(string $key)
    {
        return $this->related->prepare_column($key);
    }

    /**
     * Temporarily disable constraints for the duration of the callback.
     *
     * @param Closure $callback The callback to execute with constraints disabled
     *
     * @return mixed The return value of the callback
     *
     * @since 1.0.0
     */
    public static function without_constraints(Closure $callback)
    {
        $previous = static::$constraints;
        static::$constraints = false;

        try {
            return $callback();
        } finally {
            static::$constraints = $previous;
        }
    }


    /**
     * Handle dynamic method calls into the query builder.
     *
     * @param string $method The method name
     * @param array $parameters The method parameters
     *
     * @return mixed The result of the method call
     *
     * @throws \BadMethodCallException
     *
     * @since 1.0.0
     */
    public function __call($method, $parameters)
    {
        throw_if(
            !method_exists($this->query, $method),
            sprintf('Method %s::%s does not exist.', QueryBuilder::class, esc_html($method)),
            BadMethodCallException::class
        );

        return $this->query->$method(...$parameters);
    }
}
