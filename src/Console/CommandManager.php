<?php
/**
 * Registers framework commands with WP-CLI under a configurable base prefix.
 * Resolves command classes from the container and validates they extend CommandBase.
 * Bridges the application container and WordPress CLI for artisan-style command discovery.
 *
 * @package    Framework
 * @subpackage Console
 * @since      1.0.0
 */
namespace Framework\Console;

defined('ABSPATH') || exit;

use RuntimeException;

use function Framework\app;
use function Framework\throw_unless;

class CommandManager
{
    /**
     * The base command name
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $command_base = 'kirki';

    /**
     * Register a command
     *
     * @param string $name The name.
     * @param mixed $command The command.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function register(string $name, $command)
    {
        $command = $this->resolve($command);
        $args = $command->args();

        \WP_CLI::add_command($this->make($name), $command, $args);
    }

    /**
     * Resolve the command
     *
     * @param mixed $command The command.
     *
     * @return CommandBase
     *
     * @throws \RuntimeException
     *
     * @since 1.0.0
     */
    protected function resolve($command)
    {
        if (is_object($command)) {
            throw_unless(
                $command instanceof CommandBase,
                sprintf("Command [%s] must extend [%s]", get_class($command), CommandBase::class),
                RuntimeException::class
            );

            return $command;
        }

        throw_unless(
            class_exists($command),
            sprintf("Command class [%s] not found", $command),
            RuntimeException::class
        );

        return app()->make($command);
    }

    /**
     * Make the command name
     *
     * @param mixed $command The command.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function make($command)
    {
        return sprintf('%s %s', $this->command_base, $command);
    }
}
