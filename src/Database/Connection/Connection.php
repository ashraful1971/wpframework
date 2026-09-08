<?php
/**
 * Manage a shared PDO database connection.
 * Provide a singleton-style access point to a configured PDO instance 
 * used by the ORM for executing queries and transactions.
 * Handles DSN construction, error mode configuration, and connection lifecycle.
 * Consumers request the instance rather than constructing connections directly.
 *
 * @package    Framework
 * @subpackage Database\Connection
 * @since      1.0.0
 */
namespace Framework\Database\Connection;

defined('ABSPATH') || exit;

use Closure;
use DateTimeInterface;
use Framework\Database\Query\Expression;
use Framework\Database\Query\Model;
use Framework\Database\Query\QueryBuilder;
use Framework\Database\Query\QueryCompiler;
use Framework\Database\Schema\Compiler as SchemaCompiler;
use Framework\Exceptions\QueryException;
use Framework\Exceptions\UniqueConstraintViolationException;
use Exception;
use RuntimeException;

use function Framework\collection;
use function Framework\Polyfill\str_contains;
use function Framework\throw_anyway;
use function Framework\throw_if;

class Connection
{
    /**
     * The database connection instance.
     *
     * @var \wpdb
     *
     * @since 1.0.0
     */
    protected $db;

    /**
     * The last inserted ID.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $last_insert_id = 0;

    /**
     * Whether to log the queries
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $is_logging_queries = false;

    /**
     * The query log
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected $query_log = [];

    /**
     * The total duration of the queries
     *
     * @var float
     *
     * @since 1.0.0
     */
    protected $total_query_duration = 0.0;

    /**
     * Initialize the connection with the given configuration.
     *
     * Accepts an array of database settings and immediately attempts to
     * establish a PDO connection using those values. Instances should be
     * requested via the instance methods rather than direct construction.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        $this->connect();
    }

    /**
     * Establish the underlying PDO connection.
     *
     * Builds a MySQL DSN using charset and port defaults when not provided,
     * then creates the PDO with exceptions enabled and native prepared
     * statements. Throws an exception when connection fails.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function connect()
    {
        try {
            global $wpdb;
            $this->db = $wpdb;
        } catch (Exception $error) {
            throw_anyway("Database connection failed: " . $error->getMessage());
        }
    }

    /**
     * Get the underlying PDO instance.
     *
     * Exposes the PDO object for lower-level operations or integrations that
     * require direct database access beyond the ORM's query builder.
     *
     * @return \wpdb The active PDO connection object
     *
     * @since 1.0.0
     */
    public function get_db()
    {
        return $this->db;
    }

    /**
     * Begin a new database transaction.
     *
     * Delegates to PDO::beginTransaction and returns its boolean result.
     * Transactions allow grouping multiple statements into an atomic unit.
     *
     * @return bool True on success; false on failure
     *
     * @since 1.0.0
     */
    public function begin_transaction()
    {
        return $this->db->query('START TRANSACTION');
    }

    /**
     * Commit the current database transaction.
     *
     * Finalizes the transaction and persists the changes. Returns the PDO
     * boolean result indicating success.
     *
     * @return bool True on success; false on failure
     *
     * @since 1.0.0
     */
    public function commit()
    {
        return $this->db->query('COMMIT');
    }

    /**
     * Roll back the current database transaction.
     *
     * Reverts all changes made during the active transaction. Returns the PDO
     * boolean result indicating whether the rollback succeeded.
     *
     * @return bool True on success; false on failure
     *
     * @since 1.0.0
     */
    public function rollback()
    {
        return $this->db->query('ROLLBACK');
    }

    /**
     * Get a new query builder instance with the table name wrapped.
     *
     * @param string|Expression|Model $table The table name.
     * @param string|null $as The alias of the table.
     *
     * @return QueryBuilder
     *
     * @since 1.0.0
     */
    public function table($table, $as = null)
    {
        return $this->query()->from($table, $as);
    }

    /**
     * Insert a new record into the database
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return bool True if the insert was successful, false otherwise.
     *
     * @since 1.0.0
     */
    public function insert($query, $bindings = [])
    {
        return $this->statement($query, $bindings);
    }

    /**
     * Update the database
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return int The number of rows updated.
     *
     * @since 1.0.0
     */
    public function update($query, $bindings = [])
    {
        return $this->affecting_statement($query, $bindings);
    }

    /**
     * Delete a record from the database
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return int The number of rows deleted.
     *
     * @since 1.0.0
     */
    public function delete($query, $bindings = [])
    {
        return $this->affecting_statement($query, $bindings);
    }

    /**
     * Execute a statement and return the results
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return bool True if the statement was successful, false otherwise.
     *
     * @since 1.0.0
     */
    protected function statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $sql = $this->prepare_query($query, $bindings);

            return (bool) $this->db->query($sql);
        });
    }

    /**
     * Execute a statement and return the number of affected rows
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return int The number of affected rows.
     *
     * @since 1.0.0
     */
    public function affecting_statement($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $sql = $this->prepare_query($query, $bindings);

            $result = $this->db->query($sql);

            if ($result !== false) {
                return $this->db->rows_affected;
            }

            return 0;
        });
    }

    /**
     * Execute a raw SQL query and return the results.
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     *
     * @return array The results of the query.
     *
     * @since 1.0.0
     */
    public function select($query, $bindings = [])
    {
        return $this->run($query, $bindings, function ($query, $bindings) {
            $sql = $this->prepare_query($query, $bindings);

            return $this->db->get_results($sql, ARRAY_A);
        });
    }

    /**
     * Run a query and return the results.
     * This is for handling the mysql errors properly
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     * @param Closure $callback The callback function to execute.
     *
     * @return mixed The results of the query.
     *
     * @since 1.0.0
     */
    protected function run($query, $bindings, Closure $callback)
    {
        $start = microtime(true);

        try {
            $result = $this->run_query_callback($query, $bindings, $callback);
        } catch (QueryException $error) {
            throw $error;
        }

        $this->log_query($query, $bindings, $this->get_elapsed_time($start));

        return $result;
    }

    /**
     * Run a query callback and handle the mysql errors properly
     *
     * @param string $query The SQL query to execute.
     * @param array $bindings The bindings for the query.
     * @param Closure $callback The callback function to execute.
     *
     * @return mixed The results of the query.
     *
     * @throws Exception
     * @throws UniqueConstraintViolationException
     * @throws QueryException
     *
     * @since 1.0.0
     */
    protected function run_query_callback($query, array $bindings, Closure $callback)
    {
        try {
            $result = $callback($query, $bindings);

            if (!empty($this->db->last_error)) {
                throw new Exception($this->db->last_error);
            }

            if ($this->db->rows_affected < 0) {
                throw new Exception(sprintf(
                    'Query failed: %s',
                    $query
                ), 500);
            }

            return $result;
        } catch (Exception $error) {
            throw_if(
                $this->is_unique_constraint_error($error),
                $query,
                UniqueConstraintViolationException::class,
                $this->prepare_bindings($bindings),
                $error
            );

            throw_anyway(
                $query,
                QueryException::class,
                $this->prepare_bindings($bindings),
                $error
            );
        }
    }

    /**
     * Log a query error
     *
     * @param mixed $query The query builder instance.
     * @param mixed $bindings The bindings.
     * @param float $time The time the query took.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function log_query($query, $bindings, $time = 0.0)
    {
        $this->total_query_duration += $time;

        $query = $this->prepare_query($query, $bindings);

        if ($this->is_logging_queries) {
            $this->query_log[] = compact('query', 'bindings', 'time');
        }
    }

    /**
     * Get the elapsed time
     *
     * @param float $start The start time.
     *
     * @return float The elapsed time.
     *
     * @since 1.0.0
     */
    protected function get_elapsed_time($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    /**
     * Check if the exception is a unique constraint error
     *
     * @param Exception $exception The exception to check.
     *
     * @return bool True if the exception is a unique constraint error, false otherwise.
     *
     * @since 1.0.0
     */
    protected function is_unique_constraint_error(Exception $exception)
    {
        return boolval(preg_match('#Integrity constraint violation: 1062#i', $exception->getMessage()));
    }

    /**
     * Prepare the bindings for the query
     *
     * @param array $bindings The bindings for the query.
     *
     * @return array The prepared bindings.
     *
     * @since 1.0.0
     */
    public function prepare_bindings(array $bindings)
    {
        $compiler = $this->get_query_compiler();

        $bindings = $this->clean_null_bindings($bindings);

        foreach ($bindings as $key => $value) {
            if ($value instanceof DateTimeInterface) {
                $bindings[$key] = $value->format($compiler->get_date_format());
            } elseif (is_bool($value)) {
                $bindings[$key] = (int) $value;
            }
        }

        return $bindings;
    }

    /**
     * Prepare the query for execution
     *
     * @param string $query The query to prepare.
     * @param array $bindings The bindings for the query.
     *
     * @return string The prepared query.
     *
     * @since 1.0.0
     */
    protected function prepare_query(string $query, array $bindings = [])
    {
        $bindings = $this->prepare_bindings($bindings);

        if (empty($bindings)) {
            return $query;
        }

        return $this->db->prepare($query, $bindings);
    }

    /**
     * Clean the null bindings from the query
     * This is for preventing the query from failing when a null value is passed
     *
     * @param array $bindings The bindings for the query.
     *
     * @return array The cleaned bindings.
     *
     * @since 1.0.0
     */
    protected function clean_null_bindings(array $bindings)
    {
        return collection($bindings)->filter(function ($value) {
            return $value !== null;
        })->all();
    }

    /**
     * Get a new query builder instance.
     *
     * @return \Framework\Database\Query\QueryBuilder
     *
     * @since 1.0.0
     */
    public function query()
    {
        return new QueryBuilder(
            $this,
            $this->get_query_compiler(),
            null
        );
    }

    /**
     * Get a new query compiler instance.
     *
     * @return QueryCompiler
     *
     * @since 1.0.0
     */
    public function get_query_compiler()
    {
        return new QueryCompiler($this);
    }

    /**
     * Get a new schema compiler instance.
     *
     * @return SchemaCompiler
     *
     * @since 1.0.0
     */
    public function get_schema_compiler()
    {
        return new SchemaCompiler($this);
    }

    /**
     * Check if the value is an expression
     *
     * @param mixed $value The value to check
     *
     * @return bool True if the value is an expression, false otherwise
     *
     * @since 1.0.0
     */
    public function is_expression($value)
    {
        return $value instanceof Expression;
    }

    /**
     * Get the table prefix
     *
     * @return string The table prefix
     *
     * @since 1.0.0
     */
    public function get_table_prefix()
    {
        return $this->db->prefix;
    }

    /**
     * Quote a string value.
     *
     * @param string $value The value to quote.
     *
     * @return string The quoted value.
     *
     * @since 1.0.0
     */
    public function quote_string($value)
    {
        if (is_array($value)) {
            return implode(', ', array_map([$this, 'quote_string'], $value));
        }

        return sprintf("'%s'", esc_sql($value));
    }

    /**
     * Escape a value.
     *
     * @param mixed $value The value to escape.
     *
     * @return string The escaped value.
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    public function escape($value)
    {
        if ($value === null) {
            return 'null';
        } elseif (is_int($value) || is_float($value)) {
            return (string) $value;
        } elseif (is_bool($value)) {
            return $this->escape_bool($value);
        } elseif (is_array($value)) {
            throw new RuntimeException('Database connection does not support escaping arrays.');
        } else {
            if (str_contains($value, "\00")) {
                throw new RuntimeException('Strings with null bytes cannot be escaped.');
            }

            if (preg_match('//u', $value) === false) {
                throw new RuntimeException('Strings with invalid UTF-8 byte sequences cannot be escaped.');
            }

            return sprintf("'%s'", esc_sql($value));
        }
    }

    /**
     * Get a placeholder for a value.
     *
     * @param mixed $value The value to get a placeholder for.
     *
     * @return string The placeholder.
     *
     * @since 1.0.0
     */
    public function placeholder($value)
    {
        if (is_int($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
        }

        if (is_null($value)) {
            return 'null';
        }

        return '%s';
    }

    /**
     * Escape a boolean value.
     *
     * @param bool $value The value to escape.
     *
     * @return string The escaped value.
     *
     * @since 1.0.0
     */
    protected function escape_bool($value)
    {
        return $value ? '1' : '0';
    }

    /**
     * Get the value of an expression
     *
     * @param mixed $expression The expression to get the value of
     *
     * @return mixed The value of the expression
     *
     * @since 1.0.0
     */
    public function get_value($expression)
    {
        if ($this->is_expression($expression)) {
            return $this->get_value($expression->get_value());
        }

        return $expression;
    }

    /**
     * Create a new Expression instance
     *
     * @param string|int|float $value The value to wrap in the expression
     *
     * @return Expression
     *
     * @since 1.0.0
     */
    public function raw($value)
    {
        return new Expression($value);
    }

    /**
     * Enable query log.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function enable_query_log()
    {
        $this->is_logging_queries = true;
    }

    /**
     * Disable query log.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function disable_query_log()
    {
        $this->is_logging_queries = false;
    }

    /**
     * Flush query log.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function flush_query_log()
    {
        $this->query_log = [];
    }

    /**
     * Get the query log.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_query_log()
    {
        return $this->query_log;
    }
}
