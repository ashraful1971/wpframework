<?php
/**
 * Immutable value object describing a single HTTP cookie and its attributes.
 * Normalizes the same-site attribute and validates the name against header injection.
 * Converts itself into a setcookie() options array or a Set-Cookie header string.
 *
 * @package    Framework
 * @subpackage Http
 * @since      1.0.0
 */
namespace Framework\Http;

defined('ABSPATH') || exit;

use InvalidArgumentException;

use function Framework\throw_if;
use function Framework\throw_unless;

class Cookie
{
    /**
     * The same site lax value.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const SAME_SITE_LAX = 'Lax';

    /**
     * The same site strict value.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const SAME_SITE_STRICT = 'Strict';

    /**
     * The same site none value.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const SAME_SITE_NONE = 'None';

    /**
     * The characters that may never appear in a cookie name.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected const RESERVED_CHARACTERS = "=,; \t\r\n\v\f";

    /**
     * The name of the cookie.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $name;

    /**
     * The value of the cookie.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $value;

    /**
     * The unix timestamp at which the cookie expires. Zero means a session cookie.
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $expire;

    /**
     * The path the cookie is scoped to.
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $path;

    /**
     * The domain the cookie is scoped to.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $domain;

    /**
     * Whether the cookie is restricted to secure connections.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $secure;

    /**
     * Whether the cookie is hidden from client side scripts.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $http_only;

    /**
     * Whether the value is sent without URL encoding.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected $raw;

    /**
     * The same site policy of the cookie.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $same_site;

    /**
     * Create a new cookie instance.
     *
     * @param string $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param int $expire The unix timestamp at which the cookie expires, zero for a session cookie.
     * @param string|null $path The path the cookie is scoped to.
     * @param string|null $domain The domain the cookie is scoped to.
     * @param bool|null $secure Whether the cookie is restricted to secure connections.
     * @param bool $http_only Whether the cookie is hidden from client side scripts.
     * @param bool $raw Whether the value is sent without URL encoding.
     * @param string|null $same_site The same site policy of the cookie.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the name or the same site policy is invalid.
     *
     * @since 1.0.0
     */
    public function __construct(
        string $name,
        string $value = '',
        int $expire = 0,
        ?string $path = '/',
        ?string $domain = null,
        ?bool $secure = false,
        bool $http_only = true,
        bool $raw = false,
        ?string $same_site = null
    ) {
        $this->validate_name($name);

        $this->name = $name;
        $this->value = $value;
        $this->expire = $expire;
        $this->path = ($path === null || $path === '') ? '/' : $path;
        $this->domain = $domain;
        $this->secure = (bool) $secure;
        $this->http_only = $http_only;
        $this->raw = $raw;
        $this->same_site = $this->normalize_same_site($same_site);

        if ($this->same_site === static::SAME_SITE_NONE) {
            $this->secure = true;
        }
    }

    /**
     * Get the name of the cookie.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_name()
    {
        return $this->name;
    }

    /**
     * Get the value of the cookie.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_value()
    {
        return $this->value;
    }

    /**
     * Get the unix timestamp at which the cookie expires.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_expires_time()
    {
        return $this->expire;
    }

    /**
     * Get the number of seconds until the cookie expires.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_max_age()
    {
        $age = $this->expire - time();

        return max(0, $age);
    }

    /**
     * Get the path the cookie is scoped to.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_path()
    {
        return $this->path;
    }

    /**
     * Get the domain the cookie is scoped to.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_domain()
    {
        return $this->domain;
    }

    /**
     * Get the same site policy of the cookie.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    public function get_same_site()
    {
        return $this->same_site;
    }

    /**
     * Determine whether the cookie is restricted to secure connections.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_secure()
    {
        return $this->secure;
    }

    /**
     * Determine whether the cookie is hidden from client side scripts.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_http_only()
    {
        return $this->http_only;
    }

    /**
     * Determine whether the value is sent without URL encoding.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_raw()
    {
        return $this->raw;
    }

    /**
     * Determine whether the cookie is a session cookie.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_session()
    {
        return $this->expire === 0;
    }

    /**
     * Determine whether the cookie has already expired.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_expired()
    {
        return !$this->is_session() && $this->expire < time();
    }

    /**
     * Get the cookie as an options array for the setcookie function.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function to_options()
    {
        $options = [
            'expires' => $this->expire,
            'path' => $this->path,
            'domain' => $this->domain ?? '',
            'secure' => $this->secure,
            'httponly' => $this->http_only,
        ];

        if ($this->same_site !== null) {
            $options['samesite'] = $this->same_site;
        }

        return $options;
    }

    /**
     * Get the cookie as a Set-Cookie header string.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function to_header_string()
    {
        $value = $this->raw ? $this->value : rawurlencode($this->value);
        $header = $this->name . '=' . $value;

        if ($this->expire !== 0) {
            $header .= '; expires=' . gmdate('D, d-M-Y H:i:s T', $this->expire);
            $header .= '; Max-Age=' . $this->get_max_age();
        }

        $header .= '; path=' . $this->path;

        if (!empty($this->domain)) {
            $header .= '; domain=' . $this->domain;
        }

        if ($this->secure) {
            $header .= '; secure';
        }

        if ($this->http_only) {
            $header .= '; HttpOnly';
        }

        if ($this->same_site !== null) {
            $header .= '; SameSite=' . $this->same_site;
        }

        return $header;
    }

    /**
     * Get the string representation of the cookie.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function __toString()
    {
        return $this->to_header_string();
    }

    /**
     * Normalize the same site policy to a supported value.
     *
     * @param string|null $same_site The same site policy to normalize.
     *
     * @return string|null
     *
     * @throws \InvalidArgumentException When the policy is not supported.
     *
     * @since 1.0.0
     */
    protected function normalize_same_site($same_site)
    {
        if ($same_site === null || $same_site === '') {
            return null;
        }

        $supported = [
            'lax' => static::SAME_SITE_LAX,
            'strict' => static::SAME_SITE_STRICT,
            'none' => static::SAME_SITE_NONE,
        ];

        $normalized = strtolower((string) $same_site);

        throw_unless(
            isset($supported[$normalized]),
            sprintf('The same site attribute "%s" is invalid.', $same_site),
            InvalidArgumentException::class
        );

        return $supported[$normalized];
    }

    /**
     * Validate the cookie name against empty and reserved characters.
     *
     * @param string $name The name to validate.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the name is empty or contains reserved characters.
     *
     * @since 1.0.0
     */
    protected function validate_name(string $name)
    {
        if ($name === '') {
            throw new InvalidArgumentException('The cookie name cannot be empty.');
        }

        throw_if(
            strpbrk($name, static::RESERVED_CHARACTERS) !== false,
            sprintf('The cookie name "%s" contains invalid characters.', $name),
            InvalidArgumentException::class
        );
    }
}
