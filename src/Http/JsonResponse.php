<?php
/**
 * WP_REST_Response subclass that encodes data as JSON with configurable encoding options.
 * Preserves original data alongside the encoded content string.
 * Used by the response helper for consistent API JSON output.
 *
 * @package    Framework
 * @subpackage Http
 * @since      1.0.0
 */
namespace Framework\Http;

defined('ABSPATH') || exit;

use Framework\Contracts\Support\Arrayable;
use Framework\Contracts\Support\Jsonable;
use Framework\Http\Concerns\InteractsWithCookies;
use Framework\Supports\Arr;
use InvalidArgumentException;
use JsonSerializable;
use WP_REST_Response;

use function Framework\throw_unless;

class JsonResponse extends WP_REST_Response
{
    use InteractsWithCookies;

    /**
     * The original data that was passed to the response.
     *
     * @var mixed
     *
     * @since 1.0.0
     */
    protected $original;

    /**
     * The content of the response.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $content;

    /**
     * The encoding options for the response.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $encoding_options = 0;

    /**
     * Create a new JSON response instance.
     *
     * @param mixed $data The data to be encoded as JSON
     * @param int $status The HTTP status code
     * @param array $headers The headers to be sent with the response
     * @param int $options The JSON encoding options
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct($data = [], $status = 200, array $headers = [], int $options = 0)
    {
        $this->original = $data;
        $this->status = $status;
        $this->headers = $headers;
        $this->encoding_options = $options;

        $this->set_content($data);
        $this->set_status($status);
        $this->set_headers($headers);

        $this->set_data($this->get_content());
    }

    /**
     * Set the encoding options for the response.
     *
     * @param int $option The JSON encoding options
     *
     * @return $this The response instance for method chaining
     *
     * @since 1.0.0
     */
    public function set_encoding_options($option)
    {
        $this->encoding_options = (int) $option;

        return $this->set_content($this->get_content());
    }

    /**
     * Get the encoding options for the response.
     *
     * @return int The JSON encoding options
     *
     * @since 1.0.0
     */
    public function get_encoding_options()
    {
        return $this->encoding_options;
    }

    /**
     * Get the content of the response.
     *
     * @param bool $assoc Whether to return the content as an associative array
     * @param int $depth The maximum depth of the content
     *
     * @return mixed The content of the response
     *
     * @since 1.0.0
     */
    public function get_content($assoc = false, $depth = 512)
    {
        $status_code = (int) $this->get_status();

        $success = $status_code >= 200 && $status_code < 300;
        $content = json_decode($this->content, $assoc, $depth);

        if (!$assoc) {
            $content->success = $success;
            return $content;
        }

        return array_merge(['success' => $success], $content);
    }

    /**
     * Set the content of the response.
     *
     * @param mixed $data The data to be encoded as JSON
     *
     * @return $this The response instance for method chaining
     *
     * @throws \InvalidArgumentException
     *
     * @since 1.0.0
     */
    public function set_content($data)
    {
        $this->original = $data;

        // Clear the json error stack
        json_decode('[]');

        switch (true) {
            case $data instanceof Jsonable:
                $this->content = $data->to_json($this->encoding_options);
                break;
            case $data instanceof JsonSerializable:
                $this->content = Arr::json_encode($data->jsonSerialize(), $this->encoding_options);
                break;
            case $data instanceof Arrayable:
                $this->content = Arr::json_encode($data->to_array(), $this->encoding_options);
                break;
            default:
                $this->content = Arr::json_encode($data, $this->encoding_options);
                break;
        }

        throw_unless($this->is_valid_json(json_last_error()), json_last_error_msg(), InvalidArgumentException::class);

        return $this;
    }

    /**
     * Check if the JSON is valid.
     *
     * @param int $json_error The JSON error code
     *
     * @return bool Whether the JSON is valid
     *
     * @since 1.0.0
     */
    protected function is_valid_json($json_error)
    {
        if ($json_error === JSON_ERROR_NONE) {
            return true;
        }

        return $this->has_encoding_options(JSON_PARTIAL_OUTPUT_ON_ERROR) &&
            in_array($json_error, [
                JSON_ERROR_RECURSION,
                JSON_ERROR_INF_OR_NAN,
                JSON_ERROR_UNSUPPORTED_TYPE,
            ]);
    }

    /**
     * Check if the encoding options have a specific option set.
     *
     * @param int $option The option to check
     *
     * @return bool Whether the option is set
     *
     * @since 1.0.0
     */
    protected function has_encoding_options($option)
    {
        return (bool) ($this->encoding_options & $option);
    }
}
