<?php
/**
 * Required rule class.
 *
 * @package    Framework
 * @subpackage Validation
 * @since      1.0.0
 */
namespace Framework\Validation\Rules;

defined('ABSPATH') || exit;

use finfo;
use Framework\Filesystem\File as FilesystemFile;
use Framework\Filesystem\MimeTypes;
use Framework\Supports\Arr;
use Framework\Validation\ValidationRule;
use Framework\Supports\Str;
use InvalidArgumentException;

use function Framework\throw_anyway;


/**
 * File rule class.
 * 
 * @method $this size(string|int $size)
 * @method $this min(string|int $min)
 * @method $this max(string|int $max)
 * @method $this between(array $between)
 * @method $this image()
 * @method $this types(array $types)
 * @method $this extensions(array $extensions)
 */
class FileRule extends ValidationRule
{
    /**
     * The rule name.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected string $rule = 'file';

    /**
     * The constraints.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $constraints = [
        'size',
        'min',
        'max',
        'between',
        'image',
        'types',
        'extensions',
    ];

    /**
     * The constructor.
     *
     * @param array|null $args The arguments.
     *
     * @return void
     * 
     * @since 1.0.0
     */
    public function __construct($args = null)
    {
        parent::__construct($args);
    }

    /**
     * Validate the rule.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function validate(): bool
    {
        $this->value = $this->make_file($this->value);

        if (!$this->value || !$this->value instanceof FilesystemFile || $this->value->getRealPath() === '') {
            return $this->fails($this->default_messages['default']);
        }

        return $this->validate_constraints(function ($passed, $constraint) {
            if (!$passed) {
                return $this->fails($this->default_messages[$constraint], $this->prepare_placeholder($constraint));
            }
        });
    }

    /**
     * Prepare the placeholder.
     *
     * @param string $constraint The constraint.
     *
     * @return array The placeholder.
     *
     * @since 1.0.0
     */
    protected function prepare_placeholder($constraint)
    {
        if (in_array($constraint, ['min', 'max', 'size'])) {
            return [$constraint => $this->to_kilobytes($this->get($constraint))];
        }

        if ($constraint === 'between') {
            return [
                'min' => $this->to_kilobytes($this->get('between')[0]),
                'max' => $this->to_kilobytes($this->get('between')[1]),
            ];
        }

        return [$constraint => $this->get($constraint)];
    }

    /**
     * Validate the size constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_size()
    {
        $size = $this->to_kilobytes($this->get('size'));
        $filesize = $this->get_file_original_size($this->value);

        return $filesize === $size;
    }

    /**
     * Validate the min constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_min()
    {
        $min = $this->to_kilobytes($this->get('min'));
        $filesize = $this->get_file_original_size($this->value);

        return $filesize >= $min;
    }

    /**
     * Validate the max constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_max()
    {
        $max = $this->to_kilobytes($this->get('max'));
        $filesize = $this->get_file_original_size($this->value);

        return $filesize <= $max;
    }

    /**
     * Validate the between constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_between()
    {
        [$min, $max] = $this->get('between');
        $min = $this->to_kilobytes($min);
        $max = $this->to_kilobytes($max);
        $filesize = $this->get_file_original_size($this->value);

        return $filesize >= $min && $filesize <= $max;
    }

    /**
     * Validate the image constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_image()
    {
        $mimetype = MimeTypes::make()->get_mimetype($this->value);
        $image_mimetypes = MimeTypes::make()->images_mimetypes;

        return in_array($mimetype, $image_mimetypes, true);
    }

    /**
     * Validate the types constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_types()
    {
        $types = $this->get('types');
        $allowed_mimetypes = MimeTypes::make()->allowed_mimetypes($types);
        $mimetype = MimeTypes::make()->get_mimetype($this->value);

        return in_array($mimetype, $allowed_mimetypes, true);
    }

    /**
     * Validate the extensions constraint.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function validate_extensions()
    {
        $extensions = $this->get('extensions');
        $allowed_extensions = MimeTypes::make()->allowed_extensions($extensions);
        $possible_extensions = MimeTypes::make()->guess_extensions($this->value);

        return count(array_values(array_intersect($possible_extensions, $allowed_extensions))) > 0;
    }

    /**
     * Convert the file size to kilobytes.
     *
     * @param string|int $size The file size.
     *
     * @return int The file size in kilobytes.
     *
     * @since 1.0.0
     */
    protected function to_kilobytes($size)
    {
        if (!is_string($size)) {
            return $size;
        }

        $size = strtolower(trim($size));
        $value = (float) $size;

        switch (true) {
            case Str::ends_with($size, 'kb'):
                return $value * 1;
            case Str::ends_with($size, 'mb'):
                return $value * 1024;
            case Str::ends_with($size, 'gb'):
                return $value * 1024 * 1024;
            case Str::ends_with($size, 'tb'):
                return $value * 1024 * 1024 * 1024;
            default:
                throw_anyway('Invalid file size: ' . $size, InvalidArgumentException::class);
        }
    }

    /**
     * Get the file original size.
     *
     * @param FilesystemFile $file The file.
     *
     * @return float The file original size.
     *
     * @since 1.0.0
     */
    protected function get_file_original_size(FilesystemFile $file)
    {
        return $file->getSize() / 1024;
    }

    /**
     * Get the file original mimetype.
     *
     * @param FilesystemFile $file The file.
     *
     * @return string The file original mimetype.
     *
     * @since 1.0.0
     */
    protected function get_file_original_mimetype(FilesystemFile $file)
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);

        return strtolower($finfo->file($file->getRealPath()));
    }

    /**
     * Get the file original extension.
     *
     * @param FilesystemFile $file The file.
     *
     * @return string The file original extension.
     *
     * @since 1.0.0
     */
    protected function get_file_original_extension(FilesystemFile $file)
    {
        return strtolower($file->getExtension());
    }

    /**
     * Make the file.
     *
     * @param string|FilesystemFile $value The file value.
     *
     * @return FilesystemFile The file.
     *
     * @since 1.0.0
     */
    protected function make_file($value)
    {
        if ($value instanceof FilesystemFile) {
            return $value;
        }

        if (is_string($value)) {
            return new FilesystemFile($value, false);
        }

        throw_anyway('Invalid file value: ' . $value, InvalidArgumentException::class);
    }


    /**
     * Get the error message.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function messages()
    {
        return $this->process_messages($this->messages);
    }
}
