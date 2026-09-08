<?php
/**
 * Fileable
 *
 * @package    Framework
 * @subpackage Filesystem
 * @since      1.0.0
 */
namespace Framework\Filesystem;

defined('ABSPATH') || exit;

use function Framework\throw_unless;

/**
 * Fileable interface for filesystem operations.
 *
 * @method bool is_file()
 * @method array glob($pattern, int $flags = 0)
 * @method string basename()
 * @method string name()
 * @method string extension()
 * @method bool copy(string $target)
 * @method bool move(string $target)
 * @method bool delete(string|array $paths)
 * @method mixed chmod(int|null $mode = null)
 * @method bool append(string $data)
 * @method bool prepend(string $data)
 * @method bool put(string $data)
 * @method string get()
 * @method array json(int $flags = 0)
 * @method string hash(string $algorithm = 'md5')
 * @method bool exists()
 * @method bool missing()
 * @method string dirname()
 * @method string type()
 * @method string mime_type()
 * @method int|false size()
 * @method bool is_directory()
 * @method bool is_readable()
 * @method bool is_writable()
 * @method int|false last_modified()
 */
class Fileable
{
    /**
     * The path to the file.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $path;

    /**
     * Create a new file instance.
     *
     * @param string $path The path.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /**
     * Create a new file instance.
     *
     * @param string $path The path.
     *
     * @return static
     *
     * @since 1.0.0
     */
    public static function make(string $path)
    {
        return new static($path);
    }

    /**
     * Get the parameters for the given method.
     *
     * @param mixed $method The method name.
     * @param mixed $parameters The parameters array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function parameters($method, $parameters)
    {
        $exception_methods = ['delete', 'glob'];

        if (in_array($method, $exception_methods)) {
            return $parameters;
        }

        return array_merge([$this->path], $parameters);
    }

    /**
     * Handle dynamic method calls into the filesystem.
     *
     * @param mixed $method The method name.
     * @param mixed $parameters The parameters array.
     *
     * @return mixed
     *
     * @throws \BadMethodCallException
     *
     * @since 1.0.0
     */
    public function __call($method, $parameters)
    {
        $filesystem = new Filesystem();

        throw_unless(
            method_exists($filesystem, $method),
            "Method [$method] does not exist on [Filesystem].",
            \BadMethodCallException::class
        );

        return $filesystem->{$method}(...$this->parameters($method, $parameters));
    }
}
