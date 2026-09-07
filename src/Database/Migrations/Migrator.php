<?php
/**
 * Orchestrates running and rolling back migration classes.
 * Compares registered migrations against the repository history and invokes up/down methods.
 * Validates each migration implements the Migration contract before execution.
 *
 * @package    Framework
 * @subpackage Database\Migrations
 * @since      1.0.0
 */
namespace Framework\Database\Migrations;

defined('ABSPATH') || exit;

use Framework\Contracts\Migration;
use Framework\Supports\Facades\Schema;
use Exception;

use function Framework\throw_unless;

class Migrator
{
    /**
     * The migration repository instance.
     *
     * @var MigrationRepository|null
     *
     * @since 1.0.0
     */
    protected $repository = null;

    /**
     * Create a new migrator instance.
     *
     * @param MigrationRepository $repository The repository.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(MigrationRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Run the migrations.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function run()
    {
        $migrations = $this->repository->get_registered_migrations();
        $previous_migrations = $this->repository->get_previous_migrations();

        if (empty($migrations)) {
            return;
        }

        $batch = $this->repository->get_next_batch_number();

        foreach ($migrations as $migration) {
            $class_name = get_class($migration);

            $this->validate_migration($migration, $class_name);

            if (!empty($previous_migrations[$class_name])) {
                continue;
            }

            $migration->up();

            $this->repository->record_migration($class_name, $batch);
        }
    }

    /**
     * Rollback the migrations of the most recent batches.
     *
     * @param int $steps The number of batches to roll back.
     *
     * @return array<int, string>
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function rollback($steps = 1)
    {
        if (!$this->repository->is_rollback_enabled()) {
            return [];
        }

        $migrations = $this->repository->get_registered_migrations();

        if (empty($migrations)) {
            return [];
        }

        $targets = $this->get_rollback_targets((int) $steps);

        if (empty($targets)) {
            return [];
        }

        $rolled_back = [];

        try {
            Schema::disabled_checking_foreign_key_constraints();

            foreach (array_reverse($migrations) as $migration) {
                $class_name = get_class($migration);

                if (!in_array($class_name, $targets, true)) {
                    continue;
                }

                $this->validate_migration($migration, $class_name);

                $migration->down();

                $this->repository->forget_migration($class_name);

                $rolled_back[] = $class_name;
            }
        } finally {
            Schema::enabled_checking_foreign_key_constraints();
        }

        return $rolled_back;
    }

    /**
     * Get the migration class names belonging to the most recent batches.
     *
     * @param int $steps The number of batches to include.
     *
     * @return array<int, string>
     *
     * @since 1.0.0
     */
    protected function get_rollback_targets(int $steps)
    {
        $batches = $this->repository->get_batch_numbers();

        if (empty($batches)) {
            return [];
        }

        $targets = [];

        foreach (array_slice($batches, 0, max($steps, 1)) as $batch) {
            $targets = array_merge($targets, $this->repository->get_migrations_in_batch($batch));
        }

        return $targets;
    }

    /**
     * Refresh the database schema by undoing every applied migration.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function fresh()
    {
        $previous_migrations = $this->repository->get_previous_migrations();

        try {
            $migrations = $this->repository->get_registered_migrations();

            Schema::disabled_checking_foreign_key_constraints();

            foreach (array_reverse($migrations) as $migration) {
                if (empty($previous_migrations[get_class($migration)])) {
                    continue;
                }

                $migration->down();
            }
        } finally {
            Schema::enabled_checking_foreign_key_constraints();
            $this->repository->remove_migrations();
        }
    }

    /**
     * Get the applied or pending state of every registered migration.
     *
     * @return array<int, array{migration: string, ran: bool, batch: int|null}>
     *
     * @since 1.0.0
     */
    public function status()
    {
        $previous_migrations = $this->repository->get_previous_migrations();

        $rows = [];

        foreach ($this->repository->get_registered_migrations() as $migration) {
            $class_name = get_class($migration);
            $applied = isset($previous_migrations[$class_name]);

            $rows[] = [
                'migration' => $this->get_short_name($class_name),
                'ran'       => $applied,
                'batch'     => $applied ? (int) $previous_migrations[$class_name]['batch'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Get the class name without its namespace.
     *
     * @param string $class_name The fully qualified class name.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function get_short_name(string $class_name)
    {
        $position = strrpos($class_name, '\\');

        return $position === false ? $class_name : substr($class_name, $position + 1);
    }

    /**
     * Validate that a registered migration is usable.
     *
     * @param mixed $migration The migration instance.
     * @param string $class_name The migration class name.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    protected function validate_migration($migration, string $class_name)
    {
        throw_unless(
            class_exists($class_name),
            sprintf(
                'Class [%s] does not exist',
                $class_name
            ),
            Exception::class
        );

        throw_unless(
            $migration instanceof Migration,
            sprintf(
                'Class [%s] must implements [%s]',
                $class_name,
                Migration::class
            ),
            Exception::class
        );
    }
}
