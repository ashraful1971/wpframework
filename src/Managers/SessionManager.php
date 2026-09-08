<?php
/**
 * Session store that carries state across requests and persists it through a swappable driver.
 * Sessions start lazily: nothing is stored and no cookie is emitted until a value is written.
 * Flash data is aged at save time so a flashed value is readable on exactly one following request.
 *
 * @package    Framework
 * @subpackage Managers
 * @since      1.0.0
 */
namespace Framework\Managers;

defined('ABSPATH') || exit;

use Exception;
use Framework\Contracts\SessionHandler;
use Framework\Http\Superglobals;
use Framework\Sanitizer;
use Framework\Supports\Arr;

use function Framework\app;
use function Framework\config;
use function Framework\value;
use function Framework\with_prefix;

class SessionManager
{
    /**
     * The key holding the flash bookkeeping.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const FLASH_KEY = '_flash';

    /**
     * The key holding the parity token.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const TOKEN_KEY = '_token';

    /**
     * The key holding the flashed input of the previous request.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const OLD_INPUT_KEY = '_old_input';

    /**
     * The key holding the previous URL.
     *
     * @var string
     *
     * @since 1.0.0
     */
    public const PREVIOUS_KEY = '_previous';

    /**
     * The keys that count as bookkeeping rather than application data.
     *
     * A session holding nothing but these is pruned instead of persisted.
     *
     * @var array<int,string>
     *
     * @since 1.0.0
     */
    public const BOOKKEEPING_KEYS = [self::FLASH_KEY, self::TOKEN_KEY, self::PREVIOUS_KEY];

    /**
     * The storage driver.
     *
     * @var SessionHandler
     *
     * @since 1.0.0
     */
    protected $handler;

    /**
     * The session identifier, or null before the session starts.
     *
     * @var string|null
     *
     * @since 1.0.0
     */
    protected $id = null;

    /**
     * The session attributes.
     *
     * @var array
     *
     * @since 1.0.0
     */
    protected array $attributes = [];

    /**
     * Whether the session has started.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $started = false;

    /**
     * Whether the session changed since it was loaded.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $modified = false;

    /**
     * Whether the identifier arrived on the request rather than being generated.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $id_from_request = false;

    /**
     * Whether the client sent a session cookie on this request.
     *
     * Tracked separately from the identifier source, because migrating to a new
     * identifier must still expire the stale cookie the client is holding.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $cookie_present = false;

    /**
     * Whether the flash data has already been aged into a stored payload.
     *
     * A request can save more than once, so aging is applied to storage exactly
     * once rather than on every call.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $flash_aged = false;

    /**
     * Whether the identifier cookie for this session has been queued for emission.
     *
     * A generated identifier only reaches the client if its cookie can still be
     * sent. Once it has been, a later write in the same request is reachable
     * again and is no longer subject to the late-write rule.
     *
     * @var bool
     *
     * @since 1.0.0
     */
    protected bool $cookie_emitted = false;

    /**
     * The resolved default session attributes.
     *
     * @var array|null
     *
     * @since 1.0.0
     */
    protected $defaults = null;

    /**
     * Create a new session manager instance.
     *
     * @param SessionHandler $handler The storage driver.
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function __construct(SessionHandler $handler)
    {
        $this->handler = $handler;
    }

    /**
     * Start the session, loading any stored payload.
     *
     * Calling this more than once in a request is a no-op.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function start()
    {
        if ($this->started) {
            return $this;
        }

        $id = $this->request_id();

        if ($id !== null) {
            $this->id = $id;
            $this->id_from_request = true;
            $this->cookie_present = true;
            $this->attributes = $this->handler->read($id);
        } else {
            $this->id = $this->generate_id();
            $this->id_from_request = false;
            $this->attributes = [];
        }

        $this->started = true;

        $this->ensure_flash_structure();
        $this->ensure_token();

        return $this;
    }

    /**
     * Start the session only when the request already carries a valid identifier.
     *
     * Reads use this so a visitor without a session is never given one.
     *
     * @return bool Whether the session is started.
     *
     * @since 1.0.0
     */
    protected function start_if_available()
    {
        if ($this->started) {
            return true;
        }

        if ($this->request_id() === null) {
            return false;
        }

        $this->start();

        return true;
    }

    /**
     * Get the valid session identifier carried by the request, if any.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function request_id()
    {
        $value = $this->request_cookie($this->get_name());

        if (!is_string($value) || !$this->is_valid_id($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Read a raw cookie value sent by the client.
     *
     * @param string $name The cookie name.
     *
     * @return string|null
     *
     * @since 1.0.0
     */
    protected function request_cookie(string $name)
    {
        // Reading the session id cookie; the value is format-validated before use.
        return Superglobals::cookie($name, null, Sanitizer::KEY);
    }

    /**
     * Generate a new session identifier.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function generate_id()
    {
        try {
            return bin2hex(\random_bytes(20));
        } catch (Exception $exception) {
            return strtolower(wp_generate_password(40, false, false));
        }
    }

    /**
     * Determine whether a session identifier has the expected format.
     *
     * Anything else is rejected before it can reach a storage key.
     *
     * @param string $id The identifier to check.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_valid_id(string $id)
    {
        return (bool) preg_match('/^[a-f0-9]{40}$/', $id);
    }

    /**
     * Get a session value.
     *
     * @param string|null $key The key, or null for every value.
     * @param mixed $default The default when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get(?string $key = null, $default = null)
    {
        if (!$this->start_if_available()) {
            return is_null($key) ? [] : value($default);
        }

        if (is_null($key)) {
            return $this->attributes;
        }

        return Arr::get($this->attributes, $key, $default);
    }

    /**
     * Get every session value.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function all()
    {
        if (!$this->start_if_available()) {
            return [];
        }

        return $this->attributes;
    }

    /**
     * Get a subset of the session values.
     *
     * @param array $keys The keys to read.
     *
     * @return array
     *
     * @since 1.0.0
     */
    public function only(array $keys)
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key);
        }

        return $results;
    }

    /**
     * Determine whether a key is present and not null.
     *
     * @param string|array $key The key or keys to check.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has($key)
    {
        foreach ((array) $key as $name) {
            if (is_null($this->get($name))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether a key exists, even when its value is null.
     *
     * @param string|array $key The key or keys to check.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function exists($key)
    {
        $missing = '__framework_session_missing__';

        foreach ((array) $key as $name) {
            if ($this->get($name, $missing) === $missing) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine whether a key is absent from the session.
     *
     * @param string|array $key The key or keys to check.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function missing($key)
    {
        return !$this->exists($key);
    }

    /**
     * Write one value or many values into the session.
     *
     * @param string|array $key The key, or an array of key and value pairs.
     * @param mixed $value The value when a single key is given.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function put($key, $value = null)
    {
        $this->start();

        $values = is_array($key) ? $key : [$key => $value];

        foreach ($values as $name => $item) {
            Arr::set($this->attributes, $name, $item);
        }

        $this->modified = true;

        return $this;
    }

    /**
     * Append a value onto an array held in the session.
     *
     * @param string $key The key of the array.
     * @param mixed $value The value to append.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function push(string $key, $value)
    {
        $array = $this->get($key, []);

        if (!is_array($array)) {
            $array = [$array];
        }

        $array[] = $value;

        return $this->put($key, $array);
    }

    /**
     * Increment a numeric session value.
     *
     * @param string $key The key to increment.
     * @param int $amount The amount to add.
     *
     * @return mixed The new value.
     *
     * @since 1.0.0
     */
    public function increment(string $key, int $amount = 1)
    {
        $value = (int) $this->get($key, 0) + $amount;

        $this->put($key, $value);

        return $value;
    }

    /**
     * Decrement a numeric session value.
     *
     * @param string $key The key to decrement.
     * @param int $amount The amount to subtract.
     *
     * @return mixed The new value.
     *
     * @since 1.0.0
     */
    public function decrement(string $key, int $amount = 1)
    {
        return $this->increment($key, $amount * -1);
    }

    /**
     * Get a session value, storing the callback result when the key is absent.
     *
     * @param string $key The key to read.
     * @param callable $callback The callback producing the value.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function remember(string $key, callable $callback)
    {
        if ($this->exists($key)) {
            return $this->get($key);
        }

        $value = $callback();

        $this->put($key, $value);

        return $value;
    }

    /**
     * Read a session value and remove it.
     *
     * @param string $key The key to read.
     * @param mixed $default The default when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function pull(string $key, $default = null)
    {
        $value = $this->get($key, $default);

        $this->forget($key);

        return $value;
    }

    /**
     * Remove one or many keys from the session.
     *
     * @param string|array $keys The key or keys to remove.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function forget($keys)
    {
        if (!$this->start_if_available()) {
            return $this;
        }

        Arr::forget($this->attributes, $keys);

        $this->modified = true;

        return $this;
    }

    /**
     * Remove every value from the session.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flush()
    {
        $this->start();

        $this->attributes = [];

        $this->ensure_flash_structure();
        $this->ensure_token();

        $this->modified = true;

        return $this;
    }

    /**
     * Flash a value for the next request only.
     *
     * @param string $key The key to flash.
     * @param mixed $value The value to flash.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flash(string $key, $value = true)
    {
        $this->put($key, $value);

        $this->push_flash_key('new', $key);
        $this->remove_flash_key('old', $key);

        return $this;
    }

    /**
     * Flash a value for the current request only.
     *
     * @param string|array $key The key, or an array of key and value pairs.
     * @param mixed $value The value when a single key is given.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function now($key, $value = null)
    {
        $values = is_array($key) ? $key : [$key => $value];

        $this->put($values);

        foreach (array_keys($values) as $name) {
            $this->push_flash_key('old', (string) $name);
        }

        return $this;
    }

    /**
     * Keep every flashed value for one more request.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function reflash()
    {
        $this->start();

        $old = $this->flash_keys('old');

        $this->put(static::FLASH_KEY . '.new', array_values(array_unique(
            array_merge($this->flash_keys('new'), $old)
        )));
        $this->put(static::FLASH_KEY . '.old', []);

        return $this;
    }

    /**
     * Keep the given flashed values for one more request.
     *
     * @param string|array $keys The key or keys to keep.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function keep($keys)
    {
        $this->start();

        $keys = (array) $keys;

        $this->put(static::FLASH_KEY . '.new', array_values(array_unique(
            array_merge($this->flash_keys('new'), $keys)
        )));

        foreach ($keys as $key) {
            $this->remove_flash_key('old', (string) $key);
        }

        return $this;
    }

    /**
     * Flash the given input as the old input of the next request.
     *
     * @param array $input The input to flash.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function flash_input(array $input)
    {
        return $this->flash(static::OLD_INPUT_KEY, $input);
    }

    /**
     * Get a value from the flashed old input.
     *
     * @param string|null $key The key, or null for every value.
     * @param mixed $default The default when the key is absent.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_old_input(?string $key = null, $default = null)
    {
        $input = $this->get(static::OLD_INPUT_KEY, []);

        if (!is_array($input)) {
            $input = [];
        }

        if (is_null($key)) {
            return $input;
        }

        return Arr::get($input, $key, $default);
    }

    /**
     * Determine whether the session carries flashed old input.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_old_input()
    {
        return $this->exists(static::OLD_INPUT_KEY);
    }

    /**
     * Build the payload to store, with the flash data aged by one request.
     *
     * Aging is applied to a copy rather than to the live attributes. A request
     * saves at more than one point, and it may render its response after the
     * save, so anything the current request can still read has to survive the
     * write untouched. Mutating here would delete a value the view is about to
     * ask for, and would delete it a second time on the next save.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function aged_payload()
    {
        $payload = $this->attributes;

        $old = $this->flash_keys('old');
        $new = $this->flash_keys('new');

        if (empty($old) && empty($new)) {
            return $payload;
        }

        if (!empty($old)) {
            Arr::forget($payload, $old);
        }

        Arr::set($payload, static::FLASH_KEY . '.old', $new);
        Arr::set($payload, static::FLASH_KEY . '.new', []);

        return $payload;
    }

    /**
     * Determine whether flash aging still has to reach storage.
     *
     * A request that only consumed flash data is otherwise unmodified, yet the
     * stored payload must still be updated so the value is not read twice.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function flash_needs_aging()
    {
        if ($this->flash_aged) {
            return false;
        }

        return !empty($this->flash_keys('old')) || !empty($this->flash_keys('new'));
    }

    /**
     * Get the flash bookkeeping keys for the given bucket.
     *
     * @param string $bucket The bucket name, either old or new.
     *
     * @return array<int,string>
     *
     * @since 1.0.0
     */
    protected function flash_keys(string $bucket)
    {
        $keys = Arr::get($this->attributes, static::FLASH_KEY . '.' . $bucket, []);

        return is_array($keys) ? $keys : [];
    }

    /**
     * Add a key to a flash bookkeeping bucket.
     *
     * @param string $bucket The bucket name, either old or new.
     * @param string $key The key to add.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function push_flash_key(string $bucket, string $key)
    {
        $keys = $this->flash_keys($bucket);
        $keys[] = $key;

        Arr::set($this->attributes, static::FLASH_KEY . '.' . $bucket, array_values(array_unique($keys)));

        $this->modified = true;
    }

    /**
     * Remove a key from a flash bookkeeping bucket.
     *
     * @param string $bucket The bucket name, either old or new.
     * @param string $key The key to remove.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function remove_flash_key(string $bucket, string $key)
    {
        $keys = array_values(array_diff($this->flash_keys($bucket), [$key]));

        Arr::set($this->attributes, static::FLASH_KEY . '.' . $bucket, $keys);

        $this->modified = true;
    }

    /**
     * Ensure the flash bookkeeping structure exists.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function ensure_flash_structure()
    {
        if (!is_array(Arr::get($this->attributes, static::FLASH_KEY))) {
            Arr::set($this->attributes, static::FLASH_KEY, ['old' => [], 'new' => []]);
        }
    }

    /**
     * Ensure a parity token exists on the session.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function ensure_token()
    {
        if (!is_string(Arr::get($this->attributes, static::TOKEN_KEY))) {
            Arr::set($this->attributes, static::TOKEN_KEY, $this->generate_id());
        }
    }

    /**
     * Get the parity token.
     *
     * This exists for API parity. The framework enforces no check on it; WordPress
     * nonces remain the cross-site request forgery defence.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function token()
    {
        $this->start();

        return Arr::get($this->attributes, static::TOKEN_KEY);
    }

    /**
     * Replace the parity token with a new one.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function regenerate_token()
    {
        $this->start();

        Arr::set($this->attributes, static::TOKEN_KEY, $this->generate_id());

        $this->modified = true;

        return $this;
    }

    /**
     * Give the session a new identifier, keeping its data.
     *
     * @param bool $destroy Whether the previous stored payload is destroyed.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function regenerate(bool $destroy = false)
    {
        return $this->migrate($destroy);
    }

    /**
     * Give the session a new identifier and discard its data.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function invalidate()
    {
        $this->start();

        $this->attributes = [];

        $this->ensure_flash_structure();

        return $this->migrate(true);
    }

    /**
     * Move the session onto a new identifier.
     *
     * @param bool $destroy Whether the previous stored payload is destroyed.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function migrate(bool $destroy = false)
    {
        $this->start();

        if ($destroy && $this->id !== null) {
            $this->handler->destroy($this->id);
        }

        $this->id = $this->generate_id();
        $this->id_from_request = false;
        $this->cookie_emitted = false;

        Arr::set($this->attributes, static::TOKEN_KEY, $this->generate_id());

        $this->modified = true;

        return $this;
    }

    /**
     * Get the session identifier, starting the session when needed.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_id()
    {
        $this->start();

        return $this->id;
    }

    /**
     * Get the name of the session identifier cookie.
     *
     * @return string
     *
     * @since 1.0.0
     */
    public function get_name()
    {
        return $this->get_defaults()['cookie'];
    }

    /**
     * Determine whether this visitor has a session at all.
     *
     * True when the session already started, or when the request carries a valid
     * identifier cookie that has simply not been loaded yet. Login and logout
     * handling needs this rather than is_started(): under lazy starting a
     * visitor holding a session has usually not touched it by then, and
     * skipping them would leave a fixated identifier in place.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function has_session()
    {
        return $this->started || $this->request_id() !== null;
    }

    /**
     * Determine whether the session has started.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_started()
    {
        return $this->started;
    }

    /**
     * Determine whether the session changed since it was loaded.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_modified()
    {
        return $this->modified;
    }

    /**
     * Get the previously recorded URL.
     *
     * @param mixed $default The default when no URL was recorded.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function previous_url($default = null)
    {
        return $this->get(static::PREVIOUS_KEY . '.url', $default);
    }

    /**
     * Record the previous URL.
     *
     * @param string $url The URL to record.
     *
     * @return $this
     *
     * @since 1.0.0
     */
    public function set_previous_url(string $url)
    {
        return $this->put(static::PREVIOUS_KEY . '.url', $url);
    }

    /**
     * Persist the session through the storage driver.
     *
     * Does nothing when the session never started or did not change. A session
     * holding nothing but bookkeeping is destroyed rather than written, and a
     * session whose identifier could never reach the client is skipped.
     *
     * Safe to call more than once in a request: the routers flush cookies at
     * one point, a redirect sends at another, and the shutdown hook backstops
     * both. Only the first call ages the flash data into storage.
     *
     * @return bool Whether a payload was written.
     *
     * @since 1.0.0
     */
    public function save()
    {
        if (!$this->started) {
            return false;
        }

        if (!$this->modified && !$this->flash_needs_aging()) {
            return false;
        }

        $payload = $this->aged_payload();

        if (!$this->holds_application_data($payload)) {
            $this->prune();

            return false;
        }

        if (!$this->id_from_request && !$this->cookie_emitted && $this->headers_already_sent()) {
            $this->log_warning(sprintf(
                'Session "%s" was not stored because the response headers were already sent, '
                . 'so its identifier cookie could never reach the client.',
                $this->id
            ));

            $this->modified = false;

            return false;
        }

        $this->queue_cookie();

        $this->cookie_emitted = true;

        $written = $this->handler->write($this->id, $payload, $this->get_lifetime_seconds());

        $this->modified = false;
        $this->flash_aged = true;

        return $written;
    }

    /**
     * Determine whether a payload holds anything the application put there.
     *
     * @param array $payload The payload to inspect.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    protected function holds_application_data(array $payload)
    {
        foreach (array_keys($payload) as $key) {
            if (!in_array($key, static::BOOKKEEPING_KEYS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Destroy a session that holds no application data and expire its cookie.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function prune()
    {
        if ($this->id !== null) {
            $this->handler->destroy($this->id);
        }

        if ($this->cookie_present) {
            $defaults = $this->get_defaults();

            $this->cookie_manager()->expire($this->get_name(), $defaults['path'], $defaults['domain']);
        }

        $this->modified = false;
        $this->flash_aged = true;
        $this->cookie_emitted = false;
    }

    /**
     * Queue the session identifier cookie for emission.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function queue_cookie()
    {
        $defaults = $this->get_defaults();
        $minutes = $defaults['expire_on_close'] ? 0 : $defaults['lifetime'];

        $this->cookie_manager()->queue(
            $this->get_name(),
            $this->id,
            $minutes,
            $defaults['path'],
            $defaults['domain'],
            $defaults['secure'],
            $defaults['http_only'],
            false,
            $defaults['same_site']
        );
    }

    /**
     * Get the configured session lifetime in seconds.
     *
     * @return int
     *
     * @since 1.0.0
     */
    public function get_lifetime_seconds()
    {
        return (int) $this->get_defaults()['lifetime'] * 60;
    }

    /**
     * Get the fields that are never flashed as old input.
     *
     * @return array<int,string>
     *
     * @since 1.0.0
     */
    public function get_never_flash()
    {
        return $this->get_defaults()['dont_flash'];
    }

    /**
     * Get the default session attributes, resolving them on first use.
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
     * Resolve the default attributes from config, then framework fallbacks.
     *
     * @return array
     *
     * @since 1.0.0
     */
    protected function resolve_defaults()
    {
        $dont_flash = config('session.dont_flash', []);
        $cookie = config('session.cookie');
        $secure = config('session.secure');
        $lifetime = config('session.lifetime');

        // A config file may declare a key with a null placeholder; treat that as absent.
        return [
            'driver' => config('session.driver') ?? 'database',
            'lifetime' => (int) ($lifetime ?? 120),
            'expire_on_close' => (bool) config('session.expire_on_close', false),
            'cookie' => is_string($cookie) && $cookie !== '' ? $cookie : with_prefix('session'),
            'path' => config('session.path') ?? '/',
            'domain' => config('session.domain', null),
            'secure' => is_null($secure) ? $this->environment_secure() : (bool) $secure,
            'http_only' => (bool) (config('session.http_only') ?? true),
            'same_site' => config('session.same_site') ?? 'Lax',
            'dont_flash' => array_values(array_unique(array_merge(
                ['password', 'password_confirmation', 'current_password'],
                is_array($dont_flash) ? $dont_flash : []
            ))),
        ];
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
     * Get the cookie manager instance.
     *
     * @return CookieManager
     *
     * @since 1.0.0
     */
    protected function cookie_manager()
    {
        return app(CookieManager::class);
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
