<?php
/**
 * Trait for HTTP request classes to access uploaded files from $_FILES.
 * Normalizes nested file arrays into UploadedFile instances keyed by input name.
 * Supports retrieving single files or collections via dot notation.
 *
 * @package    Framework
 * @subpackage Http\Concerns
 * @since      1.0.0
 */
namespace Framework\Http\Concerns;

defined('ABSPATH') || exit;

use Framework\Collections\Collection;
use Framework\Filesystem\UploadedFile;
use Framework\Http\Superglobals;
use SplFileInfo;

use function Framework\deep_get;

trait InteractsWithFiles
{
    /**
     * The files array.
     *
     * @var array<string,UploadedFile>
     *
     * @since 1.0.0
     */
    protected array $files = [];

    /**
     * Get all files from the request.
     *
     * @return array<string,UploadedFile>
     *
     * @since 1.0.0
     */
    public function all_files()
    {
        return !empty($this->files)
            ? $this->files
            : $this->load_files_from_global();
    }

    /**
     * Get a file from the request.
     *
     * @param string $key The key of the file.
     * @param mixed $default The default value if the file is not found.
     *
     * @return UploadedFile|Collection<string,UploadedFile>
     *
     * @since 1.0.0
     */
    public function file(?string $key = null, $default = null)
    {
        return deep_get($this->all_files(), $key, $default);
    }

    /**
     * Create the files from the global $_FILES array.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function load_files_from_global()
    {
        $files = Superglobals::files();
        $keys = array_keys($files);

        $files = array_map(function ($file) {
            if (isset($file['name']) && is_array($file['name'])) {
                return $this->convert_uploaded_files($file);
            }

            return UploadedFile::create_from_base($file);
        }, $files);

        return $this->files = array_combine($keys, $files);
    }

    /**
     * Convert the uploaded files to an array of UploadedFile instances.
     *
     * @param array $files The array of files.
     *
     * @return array<string,UploadedFile>
     *
     * @since 1.0.0
     */
    protected function convert_uploaded_files(array $files)
    {
        $results = [];
        $results = array_fill(
            0,
            count($files['name'] ?? []),
            ['name' => '', 'type' => '', 'tmp_name' => '', 'error' => 0, 'size' => 0],
        );
        $keys = ['name', 'type', 'tmp_name', 'error', 'size'];

        foreach ($keys as $key) {
            $this->process_item($files[$key], $key, $results);
        }

        return new Collection(array_map([$this, 'create_uploaded_file'], $results));
    }

    /**
     * Process an item from the files array.
     *
     * @param array $item The item to process.
     * @param string $key The key of the item.
     * @param array $results The results.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function process_item(array $item, string $key, array &$results)
    {
        foreach ($item as $index => $value) {
            $results[$index][$key] = $value;
        }
    }

    /**
     * Check if a file exists in the request.
     *
     * @param string $key The key of the file.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function has_file(string $key)
    {
        if (!is_array($files = $this->file($key))) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if ($this->is_valid_file($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a file is valid.
     *
     * @param mixed $file The file to check.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function is_valid_file($file)
    {
        return $file instanceof SplFileInfo && $file->getPath() !== '';
    }

    /**
     * Create a new uploaded file instance.
     *
     * @param array $file The file array from the $_FILES superglobal.
     *
     * @return UploadedFile
     *
     * @since 1.0.0
     */
    protected function create_uploaded_file(array $file)
    {
        return new UploadedFile($file['tmp_name'], $file['name'], $file['type'], $file['error'], $file['size']);
    }
}
