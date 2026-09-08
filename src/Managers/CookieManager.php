<?php
/**
 * Cookie factory and queue that builds cookies and emits them before response output.
 * Resolves default attributes from application config, WordPress constants, then framework fallbacks.
 * Queued cookies are written once at a flush point and never sent twice.
 *
 * @package    Framework
 * @subpackage Managers
 * @since      1.0.0
 */
namespace Framework\Managers;

defined('ABSPATH') || exit;

use Framework\Http\Cookie;
use InvalidArgumentException;

use function Framework\app;
use function Framework\config;
use function Framework\throw_if;

class CookieManager
{
    /**
     * The number of minutes used for a long lived cookie, roughly five years.
     *
     * @var int
     *
     * @since 1.0.0
     */
    public const FOREVER = 2628000;

    /**
     * The cookies awaiting emission, keyed by name and then by path.
     *
     * @var array<string,array<string,Cookie>>
     *
     * @since 1.0.0
     */
    protected array $queued = [];

    /**
     * The resolved default cookie attributes.
     *
     * @var array|null
     *
     * @since 1.0.0
     */
    protected $defaults = null;

    /**
     * Create a new cookie instance without queueing it.
     *
     * @param string $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param int $minutes The number of minutes the cookie lives, zero for a session cookie.
     * @param string|null $path The path the cookie is scoped to.
     * @param string|null $domain The domain the cookie is scoped to.
     * @param bool|null $secure Whether the cookie is restricted to secure connections.
     * @param bool $http_only Whether the cookie is hidden from client side scripts.
     * @param bool $raw Whether the value is sent without URL encoding.
     * @param string|null $same_site The same site policy of the cookie.
     *
     * @return Cookie
     *
     * @since 1.0.0
     */
    public function make(
        string $name,
        string $value = '',
        int $minutes = 0,
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $http_only = true,
        bool $raw = false,
        ?string $same_site = null
    ) {
        $defaults = $this->get_defaults();
        $expire = $minutes === 0 ? 0 : time() + ($minutes * 60);

        return new Cookie(
            $name,
            $value,
            $expire,
            $path ?? $defaults['path'],
            $domain ?? $defaults['domain'],
            $secure ?? $defaults['secure'],
            $http_only,
            $raw,
            $same_site ?? $defaults['same_site']
        );
    }

    /**
     * Create a cookie that lives for a long time.
     *
     * @param string $name The name of the cookie.
     * @param string $value The value of the cookie.
     * @param string|null $path The path the cookie is scoped to.
     * @param string|null $domain The domain the cookie is scoped to.
     * @param bool|null $secure Whether the cookie is restricted to secure connections.
     * @param bool $http_only Whether the cookie is hidden from client side scripts.
     * @param bool $raw Whether the value is sent without URL encoding.
     * @param string|null $same_site The same site policy of the cookie.
     *
     * @return Cookie
     *
     * @since 1.0.0
     */
    public function forever(
        string $name,
        string $value = '',
        ?string $path = null,
        ?string $domain = null,
        ?bool $secure = null,
        bool $http_only = true,
        bool $raw = false,
        ?string $same_site = null
    ) {
        return $this->make($name, $value, static::FOREVER, $path, $domain, $secure, $http_only, $raw, $same_site);
    }

    /**
     * Create a cookie that instructs the browser to delete an existing cookie.
     *
     * @param string $name The name of the cookie.
     * @param string|null $path The path the cookie was scoped to.
     * @param string|null $domain The domain the cookie was scoped to.
     *
     * @return Cookie
     *
     * @since 1.0.0
     */
    public function forget(string $name, ?string $path = null, ?string $domain = null)
    {
        return $this->make($name, '', -static::FOREVER, $path, $domain);
    }

    /**
     * Queue a cookie that deletes an existing cookie.
     *
     * @param string $name The name of the cookie.
     * @param string|null $path The path the cookie was scoped to.
     * @param string|null $domain The domain the cookie was scoped to.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function expire(string $name, ?string $path = null, ?string $domain = null)
    {
        $this->queue($this->forget($name, $path, $domain));
    }

    /**
     * Queue a cookie for emission at the next flush.
     *
     * Accepts either a Cookie instance or the arguments accepted by the make method.
     *
     * @param mixed $parameters The cookie instance or the make arguments.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When no arguments are given.
     *
     * @since 1.0.0
     */
    public function queue(...$parameters)
    {
        throw_if(
            empty($parameters),
            'A cookie instance or a cookie name is required to queue a cookie.',
            InvalidArgumentException::class
        );

        $cookie = $parameters[0] instanceof Cookie
            ? $parameters[0]
            : $this->make(...$parameters);

        $this->queued[$cookie->get_name()][$cookie->get_path()] = $cookie;
    }

    /**
     * Remove a cookie from the queue before it is emitted.
     *
     * @param string $name The name of the cookie.
     * @param string|null $path The path of the cookie, or null to remove every path.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function unqueue(string $name, ?string $path = null)
    {
        if ($path === null) {
            unset($this->queued[$name]);

            return;
        }

        unset($this->queued[$name][$path]);

        if (empty($this->queued[$name])) {
            unset($this->queued[$name]);
        }
    }

    /**
     * Determine whether a cookie is queued.
     *
     * @param string $name The name of the cookie.
     * @param string|null $path The path of the cookie, or null to match any path.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_queued(string $name, ?string $path = null)
    {
        return !is_null($this->queued($name, null, $path));
    }

    /**
     * Get a queued cookie instance.
     *
     * @param string $name The name of the cookie.
     * @param mixed $default The default value when the cookie is not queued.
     * @param string|null $path The path of the cookie, or null to match any path.
     *
     * @return Cookie|mixed
     *
     * @since 1.0.0
     */
    public function queued(string $name, $default = null, ?string $path = null)
    {
        $queued = $this->queued[$name] ?? [];

        if ($path === null) {
            return empty($queued) ? $default : reset($queued);
        }

        return $queued[$path] ?? $default;
    }

    /**
     * Get every queued cookie instance.
     *
     * @return array<int,Cookie>
     *
     * @since 1.0.0
     */
    public function get_queued_cookies()
    {
        $cookies = [];

        foreach ($this->queued as $paths) {
            foreach ($paths as $cookie) {
                $cookies[] = $cookie;
            }
        }

        return $cookies;
    }

    /**
     * Emit every queued cookie and clear the queue.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function flush_queued_cookies()
    {
        $cookies = $this->get_queued_cookies();

        $this->queued = [];

        foreach ($cookies as $cookie) {
            $this->write($cookie);
        }
    }

    /**
     * Set the default path and domain applied to new cookies.
     *
     * @param string|null $path The default path.
     * @param string|null $domain The default domain.
     * @param bool $secure Whether cookies default to secure connections only.
     * @param string|null $same_site The default same site policy.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function set_default_path_and_domain(
        ?string $path,
        ?string $domain,
        bool $secure = false,
        ?string $same_site = null
    ) {
        $this->defaults = [
            'path' => $path ?? '/',
            'domain' => $domain,
            'secure' => $secure,
            'same_site' => $same_site,
        ];

        return $this;
    }

    /**
     * Get the default cookie attributes, resolving them on first use.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function get_defaults()
    {
        if ($this->defaults === null) {
            $this->defaults = $this->resolve_defaults();
        }

        return $this->defaults;
    }

    /**
     * Resolve the default attributes from config, the WordPress environment, then framework fallbacks.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_defaults()
    {
        return [
            'path' => config('cookie.path', $this->environment_path()),
            'domain' => config('cookie.domain', $this->environment_domain()),
            'secure' => (bool) config('cookie.secure', $this->environment_secure()),
            'same_site' => config('cookie.same_site', Cookie::SAME_SITE_LAX),
        ];
    }

    /**
     * Get the cookie path defined by the WordPress installation.
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function environment_path()
    {
        if (defined('COOKIEPATH') && COOKIEPATH !== '') {
            return COOKIEPATH;
        }

        return '/';
    }

    /**
     * Get the cookie domain defined by the WordPress installation.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function environment_domain()
    {
        if (defined('COOKIE_DOMAIN') && COOKIE_DOMAIN !== '' && COOKIE_DOMAIN !== false) {
            return COOKIE_DOMAIN;
        }

        return null;
    }

    /**
     * Determine whether the current request is served over TLS.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function environment_secure()
    {
        return function_exists('is_ssl') ? (bool) is_ssl() : false;
    }

    /**
     * Write a cookie unless the response headers have already been sent.
     *
     * @param Cookie $cookie The cookie to write.
     *
     * @return bool Whether the cookie was written.
     *
     * @since 1.0.0
     */
    protected function write(Cookie $cookie)
    {
        if ($this->headers_already_sent()) {
            $this->log_warning(
                sprintf('Cookie "%s" was not sent because the headers were already sent.', $cookie->get_name())
            );

            return false;
        }

        return $this->send_cookie($cookie);
    }

    /**
     * Send a cookie to the browser.
     *
     * @param Cookie $cookie The cookie to send.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function send_cookie(Cookie $cookie)
    {
        if ($cookie->is_raw()) {
            return setrawcookie($cookie->get_name(), $cookie->get_value(), $cookie->to_options());
        }

        return setcookie($cookie->get_name(), $cookie->get_value(), $cookie->to_options());
    }

    /**
     * Determine whether the response headers have already been sent.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function headers_already_sent()
    {
        return headers_sent();
    }

    /**
     * Log a warning message.
     *
     * @param string $message The message to log.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function log_warning(string $message)
    {
        app(LogManager::class)->warning($message);
    }
}
