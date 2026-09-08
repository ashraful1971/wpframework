<?php
/**
 * Compiles database table structure definitions into executable SQL statements.
 * Translates fluent schema blueprints into CREATE and ALTER commands for WordPress database tables.
 * Coordinates column modifiers, indexes, and foreign keys through the schema definition layer.
 *
 * @package    Framework
 * @subpackage Database\Schema
 * @since      1.0.0
 */
namespace Framework\Database\Schema;

defined('ABSPATH') || exit;

use Framework\Database\Query\Expression;
use Framework\Database\Schema\Definitions\Definition;
use Framework\Database\Schema\Definitions\ForeignKeyDefinition;
use Exception;
use Framework\Database\Connection\Connection;

use function Framework\throw_anyway;
use function Framework\throw_if;
use function Framework\throw_unless;

class Compiler
{
    /**
     * List of column modifiers to apply.
     *
     * @var list<string>
     *
     * @since 1.0.0
     */
    protected $modifiers = ['unsigned', 'nullable', 'default', 'auto_increment', 'comment', 'position'];

    /**
     * List of table commands to generate.
     *
     * @var list<string>
     *
     * @since 1.0.0
     */
    protected $commands = ['primary', 'unique', 'index', 'foreign'];

    /**
     * The database connection instance.
     *
     * @var Connection
     *
     * @since 1.0.0
     */
    protected $connection = null;

    /**
     * Compiler constructor.
     *
     * @param Connection $connection The database connection instance.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Compile the SQL statement for creating a table.
     *
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function compile_create(Structure $structure)
    {
        $sql = $this->compile_create_table($structure);
        $sql = $this->compile_create_engine($sql, $structure);
        $sql = $this->compile_create_encoding($sql, $structure);

        return $sql;
    }

    /**
     * Compile the CREATE TABLE SQL statement.
     *
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function compile_create_table(Structure $structure)
    {
        $table_name = $structure->get_table();
        $columns = $this->compile_table_structure($structure);

        $this->extract_commands_from_columns($structure);
        $commands = $this->compile_commands($structure);

        return sprintf(
            'CREATE TABLE IF NOT EXISTS %s (%s)',
            $structure->wrap_table($table_name),
            implode(', ', $columns) . (!$commands ? '' : ',' . $commands)
        );
    }

    /**
     * Compile all table commands (primary, unique, index, foreign).
     *
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function compile_commands(Structure $structure)
    {
        $command_sql = [];

        foreach ($this->commands as $name) {
            $command_method = 'generate_' . strtolower($name);
            $command_sql[] = $this->$command_method($structure);
        }

        return implode(", ", array_filter($command_sql));
    }

    /**
     * Generate the PRIMARY KEY SQL statement.
     *
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function generate_primary(Structure $structure)
    {
        if (!$this->has_command($structure, 'primary')) {
            return;
        }

        $primary = $this->get_command($structure, 'primary');

        $key_name = $primary['name'];
        $keys = $primary['keys'];

        if (!empty($key_name)) {
            return sprintf(
                ' CONSTRAINT `%s` PRIMARY KEY (`%s`)',
                $key_name,
                implode('`, `', $keys)
            );
        }

        return sprintf(
            ' PRIMARY KEY (`%s`)',
            implode('`, `', $keys)
        );
    }

    /**
     * Generate the UNIQUE KEY SQL statements.
     *
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function generate_unique(Structure $structure)
    {
        if (!$this->has_command($structure, 'unique')) {
            return;
        }

        $unique = $this->get_command($structure, 'unique');

        if (empty($unique)) {
            return;
        }

        $sql = [];

        foreach ($unique as $command) {
            $key_name = $command['name'];
            $keys = $command['keys'];

            $sql[] = sprintf(
                ' UNIQUE KEY `%s` (`%s`)',
                $key_name,
                implode('`, `', $keys)
            );
        }

        return implode(", ", $sql);
    }

    /**
     * Generate the INDEX KEY SQL statements.
     *
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function generate_index(Structure $structure)
    {
        if (!$this->has_command($structure, 'index')) {
            return;
        }

        $index = $this->get_command($structure, 'index');

        if (empty($index)) {
            return;
        }

        $sql = [];

        foreach ($index as $command) {
            $key_name = $command['name'];
            $keys = $command['keys'];

            $sql[] = sprintf(
                ' KEY `%s` (`%s`)',
                $key_name,
                implode('`, `', $keys)
            );
        }

        return implode(", ", $sql);
    }

    /**
     * Generate the FOREIGN KEY SQL statements.
     *
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function generate_foreign(Structure $structure)
    {
        if (!$this->has_command($structure, 'foreign')) {
            return;
        }

        $foreign = $this->get_command($structure, 'foreign');

        if (empty($foreign)) {
            return;
        }

        $sql = [];

        foreach ($foreign as $command) {
            $sql[] = $this->compile_foreign($command, $structure);
        }

        return implode(", ", $sql);
    }

    /**
     * Compile a single FOREIGN KEY SQL statement.
     *
     * @param ForeignKeyDefinition $command The command.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function compile_foreign(ForeignKeyDefinition $command, Structure $structure)
    {
        if (!$command->column || !$command->on || !$command->references) {
            return;
        }

        $sql = sprintf(
            'CONSTRAINT `%s` FOREIGN KEY (`%s`) REFERENCES %s (`%s`)',
            $this->get_foreign_key_name($command, $structure),
            $command->column,
            $structure->connection()->get_query_compiler()->wrap_table($command->on),
            $command->references
        );

        if ($command->on_update) {
            $sql .= sprintf(' ON UPDATE %s', $command->on_update);
        }

        if ($command->on_delete) {
            $sql .= sprintf(' ON DELETE %s', $command->on_delete);
        }

        return $sql;
    }

    /**
     * Get the constraint name to use for a foreign key.
     *
     * @param ForeignKeyDefinition $command The command.
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_foreign_key_name(ForeignKeyDefinition $command, Structure $structure)
    {
        if (!empty($command->name)) {
            return $structure->format_key_name($command->name);
        }

        return $structure->format_key_name(
            sprintf('%s_%s_foreign', $structure->get_table(), $command->column)
        );
    }

    /**
     * Compile the SQL statement for altering an existing table.
     *
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function compile_alter(Structure $structure)
    {
        $this->extract_commands_from_columns($structure);

        $clauses = array_merge(
            $this->compile_alter_columns($structure),
            $this->compile_alter_renames($structure),
            $this->compile_alter_additions($structure),
            $this->compile_alter_drops($structure)
        );

        throw_if(
            empty($clauses),
            sprintf(
                'No changes were defined for table [%s].',
                $structure->get_table()
            ),
            Exception::class
        );

        return sprintf(
            'ALTER TABLE %s %s',
            $structure->wrap_table($structure->get_table()),
            implode(', ', $clauses)
        );
    }

    /**
     * Compile the added and modified column clauses.
     *
     * @param Structure $structure The structure.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    protected function compile_alter_columns(Structure $structure)
    {
        $clauses = [];

        foreach ($structure->get_columns() as $column) {
            $clauses[] = sprintf(
                '%s %s',
                $column->change ? 'MODIFY COLUMN' : 'ADD COLUMN',
                $this->compile_column($column, $structure)
            );
        }

        return $clauses;
    }

    /**
     * Compile the column rename clauses.
     *
     * @param Structure $structure The structure.
     *
     * @return array<int, string>
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function compile_alter_renames(Structure $structure)
    {
        $commands = $structure->get_commands();

        if (empty($commands['rename_column'])) {
            return [];
        }

        $clauses = [];

        foreach ($commands['rename_column'] as $rename) {
            $clauses[] = sprintf(
                'CHANGE COLUMN `%s` `%s` %s',
                $rename['from'],
                $rename['to'],
                $this->get_existing_column_definition($structure, $rename['from'])
            );
        }

        return $clauses;
    }

    /**
     * Compile the key and constraint clauses added to an existing table.
     *
     * @param Structure $structure The structure.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    protected function compile_alter_additions(Structure $structure)
    {
        $commands = $structure->get_commands();
        $clauses = [];

        if (!empty($commands['primary'])) {
            $clauses[] = sprintf(
                'ADD PRIMARY KEY (`%s`)',
                implode('`, `', $commands['primary']['keys'])
            );
        }

        foreach ($commands['unique'] ?? [] as $command) {
            $clauses[] = sprintf(
                'ADD UNIQUE KEY `%s` (`%s`)',
                $command['name'],
                implode('`, `', $command['keys'])
            );
        }

        foreach ($commands['index'] ?? [] as $command) {
            $clauses[] = sprintf(
                'ADD INDEX `%s` (`%s`)',
                $command['name'],
                implode('`, `', $command['keys'])
            );
        }

        foreach ($commands['foreign'] ?? [] as $command) {
            $foreign = $this->compile_foreign($command, $structure);

            if ($foreign) {
                $clauses[] = sprintf('ADD %s', $foreign);
            }
        }

        return $clauses;
    }

    /**
     * Compile the drop clauses for an existing table.
     *
     * @param Structure $structure The structure.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    protected function compile_alter_drops(Structure $structure)
    {
        $commands = $structure->get_commands();
        $clauses = [];

        foreach ($commands['drop_foreign'] ?? [] as $name) {
            $clauses[] = sprintf('DROP FOREIGN KEY `%s`', $name);
        }

        foreach ($commands['drop_index'] ?? [] as $name) {
            $clauses[] = sprintf('DROP INDEX `%s`', $name);
        }

        if (!empty($commands['drop_primary'])) {
            $clauses[] = 'DROP PRIMARY KEY';
        }

        foreach ($commands['drop_column'] ?? [] as $column) {
            $clauses[] = sprintf('DROP COLUMN `%s`', $column);
        }

        return $clauses;
    }

    /**
     * Read the live definition of an existing column from the database.
     *
     * @param Structure $structure The structure.
     * @param string $column The column name.
     *
     * @return string
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function get_existing_column_definition(Structure $structure, string $column)
    {
        $table = $this->connection->get_table_prefix() . $structure->get_table();

        $existing_columns = $this->connection->get_db()->get_results(
            $this->compile_database_columns($table),
            ARRAY_A
        );

        foreach ((array) $existing_columns as $existing) {
            if (strcasecmp($existing['name'], $column) === 0) {
                return $this->build_existing_column_definition($existing);
            }
        }

        throw_anyway(
            sprintf(
                'Column [%s] does not exist on table [%s].',
                $column,
                $structure->get_table()
            ),
            Exception::class
        );
    }

    /**
     * Rebuild a column definition from its information schema row.
     *
     * @param array $existing The information schema row.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function build_existing_column_definition(array $existing)
    {
        $sql = $existing['type'];

        $sql .= strcasecmp((string) $existing['nullable'], 'YES') === 0 ? ' null' : ' not null';

        $default = is_null($existing['default'])
            ? null
            : $this->get_existing_default_value($existing['default']);

        if (!is_null($default)) {
            $sql .= sprintf(' default %s', $default);
        }

        if (!empty($existing['extra']) && stripos($existing['extra'], 'auto_increment') !== false) {
            $sql .= ' auto_increment';
        }

        if (!empty($existing['comment'])) {
            $sql .= sprintf(" comment '%s'", addslashes($existing['comment']));
        }

        return $sql;
    }

    /**
     * Format a default value read back from the information schema.
     *
     * MariaDB reports defaults already in SQL literal form and uses the bare
     * string NULL to mean no default, while MySQL reports the raw value.
     *
     * @param string $value The stored default value.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function get_existing_default_value($value)
    {
        $value = (string) $value;

        if (strcasecmp($value, 'NULL') === 0) {
            return null;
        }

        if (stripos($value, 'current_timestamp') !== false) {
            return $value;
        }

        if (is_numeric($value)) {
            return $value;
        }

        if (strlen($value) > 1 && strpos($value, "'") === 0 && substr($value, -1) === "'") {
            return $value;
        }

        return sprintf("'%s'", $value);
    }

    /**
     * Append the character set and collation to the SQL statement.
     *
     * @param mixed $sql The sql.
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function compile_create_encoding($sql, Structure $structure)
    {
        $charset = $structure->get_charset();
        $collate = $structure->get_collate();

        $sql .= sprintf(
            ' DEFAULT CHARSET=%s COLLATE=%s',
            $charset,
            $collate
        );

        return $sql;
    }

    /**
     * Append the storage engine to the SQL statement.
     *
     * @param mixed $sql The sql.
     * @param Structure $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function compile_create_engine($sql, Structure $structure)
    {
        $engine = $structure->get_engine();

        $sql .= sprintf(
            ' ENGINE=%s',
            $engine
        );

        return $sql;
    }

    /**
     * Compile the column definitions for the table.
     *
     * @param Structure $structure The structure.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    protected function compile_table_structure(Structure $structure)
    {
        $column_structure = [];
        foreach ($structure->get_columns() as $column) {
            $column_structure[] = $this->compile_column($column, $structure);
        }
        return $column_structure;
    }

    /**
     * Extract and register commands (primary, unique, index) from column definitions.
     *
     * @param Structure $structure The structure.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function extract_commands_from_columns(Structure $structure)
    {
        $columns = $structure->get_columns();

        foreach ($columns as $column) {
            if ($column->primary) {
                $structure->primary([$column->name]);
            }

            if ($column->unique) {
                $structure->unique([$column->name]);
            }

            if ($column->index) {
                $structure->index([$column->name]);
            }
        }
    }

    /**
     * Compile a single column definition.
     *
     * @param mixed $column The column.
     * @param mixed $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function compile_column($column, $structure)
    {
        $sql = sprintf(
            '%s %s',
            $this->get_column_name($column),
            $this->get_column_type($column)
        );

        return $this->add_modifiers($sql, $column, $structure);
    }


    /**
     * Compile the columns query.
     *
     * @param string $table The table.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function compile_database_columns($table)
    {
        return sprintf(
            'select column_name as `name`, data_type as `type_name`, column_type as `type`, '
            . 'collation_name as `collation`, is_nullable as `nullable`, '
            . 'column_default as `default`, column_comment as `comment`, '
            . 'generation_expression as `expression`, extra as `extra` '
            . 'from information_schema.columns where table_schema = schema() and table_name = %s '
            . 'order by ordinal_position asc',
            $this->connection->quote_string($table)
        );
    }

    /**
     * Add all applicable modifiers to a column definition.
     *
     * @param mixed $sql The sql.
     * @param mixed $column The column.
     * @param mixed $structure The structure.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function add_modifiers($sql, $column, $structure)
    {
        foreach ($this->modifiers as $modifier) {
            $modifier_method = 'apply_' . strtolower($modifier);
            $sql .= $this->$modifier_method($column, $structure);
        }

        return $sql;
    }

    /**
     * Add UNSIGNED modifier to a column if applicable.
     *
     * @param mixed $column The column.
     * @param mixed $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_unsigned($column, $structure)
    {
        if ($column->unsigned) {
            return ' unsigned';
        }
    }

    /**
     * Add NULL/NOT NULL modifier to a column if applicable.
     *
     * @param Definition $column The column.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_nullable(Definition $column, Structure $structure)
    {
        if (isset($column->nullable)) {
            return $column->nullable ? ' null' : ' not null';
        }

        return ' not null';
    }

    /**
     * Add DEFAULT value modifier to a column if applicable.
     *
     * @param Definition $column The column.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_default(Definition $column, Structure $structure)
    {
        if (!is_null($column->default)) {
            return sprintf(' default %s', $this->get_default_value($column->default));
        }
    }

    /**
     * Add COMMENT modifier to a column if applicable.
     *
     * @param Definition $column The column.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_comment(Definition $column, Structure $structure)
    {
        if ($column->comment) {
            return sprintf(" comment '%s'", addslashes($column->comment));
        }
    }

    /**
     * Add AUTO_INCREMENT modifier to a column if applicable.
     *
     * @param Definition $column The column.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_auto_increment(Definition $column, Structure $structure)
    {
        if ($column->auto_increment) {
            return ' auto_increment';
        }
    }

    /**
     * Add the column position modifier when altering an existing table.
     *
     * @param Definition $column The column.
     * @param Structure $structure The structure.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function apply_position(Definition $column, Structure $structure)
    {
        if (!$structure->is_altering()) {
            return;
        }

        if ($column->first) {
            return ' FIRST';
        }

        if ($column->after) {
            return sprintf(' AFTER `%s`', $column->after);
        }
    }

    /**
     * Format the default value for SQL.
     *
     * @param mixed $value The value.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_default_value($value)
    {
        if ($value instanceof Expression) {
            return $this->get_value($value);
        }

        if (is_bool($value)) {
            return sprintf("'%d'", (int) $value);
        }

        return sprintf("'%s'", $value);
    }

    /**
     * Get the value.
     *
     * @param mixed $value The value.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    protected function get_value($value)
    {
        if ($value instanceof Expression) {
            return $this->get_value($value->get_value());
        }

        return $value;
    }

    /**
     * Get the formatted column name for SQL.
     *
     * @param mixed $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_column_name($column)
    {
        return sprintf('`%s`', $column->name);
    }

    /**
     * Get the SQL data type for a column.
     *
     * @param mixed $column The column.
     *
     * @return string
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function get_column_type($column)
    {
        $column_type = $column->type;
        $getter_method = 'type_' . strtolower($column_type);

        throw_unless(
            method_exists($this, $getter_method),
            sprintf('Method %s not found.', $getter_method),
            Exception::class
        );

        return $this->$getter_method($column);
    }

    /**
     * Get the SQL type for a string column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_string(Definition $column)
    {
        return sprintf('varchar(%d)', $column->length);
    }

    /**
     * Get the SQL type for a text column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_text(Definition $column)
    {
        return 'text';
    }

    /**
     * Get the SQL type for a medium text column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_medium_text(Definition $column)
    {
        return 'mediumtext';
    }

    /**
     * Get the SQL type for a long text column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_long_text(Definition $column)
    {
        return 'longtext';
    }

    /**
     * Get the SQL type for a big integer column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_big_integer(Definition $column)
    {
        return 'bigint';
    }

    /**
     * Get the SQL type for an integer column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_integer(Definition $column)
    {
        return 'int';
    }

    /**
     * Get the SQL type for a float column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_float(Definition $column)
    {
        if ($column->precision) {
            return sprintf('float(%d)', $column->precision);
        }

        return 'float';
    }

    /**
     * Get the SQL type for a double column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_double(Definition $column)
    {
        return 'double';
    }

    /**
     * Get the SQL type for a decimal column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_decimal(Definition $column)
    {
        return sprintf('decimal(%d, %d)', $column->total, $column->places);
    }

    /**
     * Get the SQL type for a boolean column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_boolean(Definition $column)
    {
        return 'tinyint(1)';
    }

    /**
     * Get the SQL type for a tinyint column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_tinyint(Definition $column)
    {
        if ($column->length) {
            return sprintf('tinyint(%d)', $column->length);
        }

        return 'tinyint';
    }

    /**
     * Get the SQL type for a date column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_date(Definition $column)
    {
        return 'date';
    }

    /**
     * Get the SQL type for a datetime column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_datetime(Definition $column)
    {
        $current = $column->precision ? sprintf('CURRENT_TIMESTAMP(%d)', $column->precision) : 'CURRENT_TIMESTAMP';

        if ($column->use_current) {
            $column->default(new Expression($current));
        }

        return $column->precision ? sprintf('datetime(%d)', $column->precision) : 'datetime';
    }

    /**
     * Get the SQL type for a time column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_time(Definition $column)
    {
        return $column->precision ? sprintf('time(%d)', $column->precision) : 'time';
    }

    /**
     * Get the SQL type for a timestamp column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_timestamp(Definition $column)
    {
        $current = $column->precision ? sprintf('CURRENT_TIMESTAMP(%d)', $column->precision) : 'CURRENT_TIMESTAMP';

        if ($column->use_current) {
            $column->default(new Expression($current));
        }

        return $column->precision ? sprintf('timestamp(%d)', $column->precision) : 'timestamp';
    }

    /**
     * Get the SQL type for an enum column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_enum(Definition $column)
    {
        return sprintf("enum('%s')", implode("', '", $column->values));
    }

    /**
     * Get the SQL type for a set column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_set(Definition $column)
    {
        return sprintf('set(%s)', implode(',', $column->values));
    }

    /**
     * Get the SQL type for an IP address column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_ip_address(Definition $column)
    {
        return 'varchar(45)';
    }

    /**
     * Get the SQL type for a UUID column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_uuid(Definition $column)
    {
        return 'char(36)';
    }

    /**
     * Get the SQL type for a year column.
     *
     * @param Definition $column The column.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function type_year(Definition $column)
    {
        return 'year';
    }

    /**
     * Check if a command exists in the structure.
     *
     * @param Structure $structure The structure.
     * @param mixed $name The name.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function has_command(Structure $structure, $name)
    {
        $commands = $structure->get_commands();
        return !empty($commands[$name]);
    }

    /**
     * Get a command from the structure.
     *
     * @param Structure $structure The structure.
     * @param mixed $name The name.
     *
     * @return mixed|null
     *
     * @since 1.0.0
     */
    protected function get_command(Structure $structure, $name)
    {
        return $this->has_command($structure, $name) ? $structure->get_commands()[$name] : null;
    }
}
