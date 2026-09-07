<?php
/**
 * Builds multipart/form-data request bodies with configurable boundary strings.
 * Encodes fields and file attachments into a single stream for HTTP client uploads.
 * Used by the Http client when sending as_multipart requests.
 *
 * @package    Framework
 * @subpackage Http\Client
 * @since      1.0.0
 */
namespace Framework\Http\Client;

defined('ABSPATH') || exit;

use Framework\Collections\Collection;
use UnexpectedValueException;

use function Framework\collection;
use function Framework\throw_unless;

class MultipartStream
{
    /**
     * The boundary string used to separate parts of the multipart stream.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $boundary;

    /**
     * The stream of data to be sent.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $stream;

    /**
     * Create a new MultipartStream instance.
     *
     * @param array $data The data to be sent.
     * @param string|null $boundary The boundary string to use.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(array $data, ?string $boundary = null)
    {
        $this->boundary = $boundary ?? "MultipartBoundary" . wp_generate_password(20, false);
        $this->stream = $this->create_stream($data);
    }

    /**
     * Get the boundary string used to separate parts of the multipart stream.
     *
     * @return string The boundary string.
     *
     * @since 1.0.0
     */
    public function boundary()
    {
        return $this->boundary;
    }

    /**
     * Get the stream of data to be sent.
     *
     * @return string The stream of data.
     *
     * @since 1.0.0
     */
    public function payload()
    {
        return $this->stream;
    }

    /**
     * Create the stream of data to be sent.
     *
     * @param array $data The data to be sent.
     *
     * @return string The stream of data.
     *
     * @throws \UnexpectedValueException
     *
     * @since 1.0.0
     */
    protected function create_stream(array $data)
    {
        $stream = collection();

        foreach ($data as $item) {
            if (!is_array($item)) {
                throw new UnexpectedValueException('Invalid data format');
            }

            $stream->push($this->create_stream_item($item));
        }

        $stream->push("--{$this->boundary}--\r\n");

        return $stream->join("");
    }

    /**
     * Create a stream item from the given data.
     *
     * @param array $item The data to be sent.
     *
     * @return string The stream item.
     *
     * @throws \UnexpectedValueException
     *
     * @since 1.0.0
     */
    protected function create_stream_item(array $item)
    {
        foreach (['contents', 'name'] as $key) {
            throw_unless(array_key_exists($key, $item), "Missing {$key} in item", UnexpectedValueException::class);
        }

        $name = $item['name'];
        $contents = $item['contents'];
        $filename = $item['filename'] ?? null;
        $headers = $item['headers'] ?? [];

        return $this->create_element($name, $contents, $filename, $headers);
    }

    /**
     * Create a stream element from the given data.
     *
     * @param string $name The name of the element.
     * @param string $contents The contents of the element.
     * @param string|null $filename The filename of the element.
     * @param array $headers The headers of the element.
     *
     * @return string The stream element.
     *
     * @since 1.0.0
     */
    protected function create_element($name, $contents, ?string $filename = null, array $headers = [])
    {
        if (!empty($filename)) {
            return $this->create_file_stream($name, $contents, $filename, $headers);
        }

        return $this->create_field_stream($name, $contents, $headers);
    }

    /**
     * Create a file stream from the given data.
     *
     * @param string $name The name of the element.
     * @param string $contents The contents of the element.
     * @param string $filename The filename of the element.
     * @param array $headers The headers of the element.
     *
     * @return string The file stream.
     *
     * @since 1.0.0
     */
    protected function create_file_stream($name, $contents, $filename, array $headers = [])
    {
        $headers['Content-Type'] ??= $this->get_mimetype($filename);
        $headers['Content-Length'] ??= strlen($contents);

        $payload = '';
        $payload .= "--{$this->boundary}\r\n";
        $payload .= "Content-Disposition: form-data; name=\"$name\"; filename=\"$filename\"\r\n";
        $payload .= $this->create_headers($headers);
        $payload .= $contents . "\r\n";

        return $payload;
    }

    /**
     * Get the mimetype of the file.
     *
     * @param string $filename The filename of the file.
     *
     * @return string The mimetype of the file.
     *
     * @since 1.0.0
     */
    protected function get_mimetype(string $filename)
    {
        return wp_check_filetype($filename)['type'] ?? 'application/octet-stream';
    }

    /**
     * Create a field stream from the given data.
     *
     * @param string $name The name of the element.
     * @param string $contents The contents of the element.
     * @param array $headers The headers of the element.
     *
     * @return string The field stream.
     *
     * @since 1.0.0
     */
    protected function create_field_stream($name, $contents, array $headers = [])
    {
        $payload = '';
        $payload .= "--{$this->boundary}\r\n";
        $payload .= "Content-Disposition: form-data; name=\"$name\"\r\n";

        if (!empty($headers)) {
            $payload .= $this->create_headers($headers);
        } else {
            $payload .= "\r\n";
        }

        $payload .= $contents . "\r\n";

        return $payload;
    }

    /**
     * Create headers for the stream.
     *
     * @param array $headers The headers to create.
     *
     * @return string The headers.
     *
     * @since 1.0.0
     */
    protected function create_headers(array $headers)
    {
        $payload = '';

        foreach ($headers as $name => $header) {
            $payload .= "{$name}: {$header}\r\n";
        }

        return trim($payload) . "\r\n\r\n";
    }
}
