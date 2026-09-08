<?php
/**
 * Middleware to ensure the user is authenticated.
 * Blocks access to routes unless the user is logged in.
 *
 * @package    Framework
 * @subpackage Middlewares
 * @since      1.0.0
 */
namespace Framework\Middlewares;

defined('ABSPATH') || exit;

use Framework\Contracts\Middleware;
use Framework\Contracts\Request;
use Framework\Exceptions\AuthorizationException;

use function Framework\message;
use function Framework\throw_anyway;

class AuthMiddleware implements Middleware
{
    /**
     * Handle the incoming request and determine if the user is authenticated.
     *
     * @param Request $request The incoming request instance.
     * @param callable $next The next middleware callback.
     *
     * @return mixed The result of the next middleware or a response.
     *
     * @throws AuthorizationException
     *
     * @since 1.0.0
     */
    public function handle(Request $request, callable $next)
    {
        if (is_user_logged_in()) {
            $request->from_auth_middleware = 'from_auth_middleware';
            return $next($request);
        };

        throw_anyway(message('auth.logged_in_required'), AuthorizationException::class);
    }
}
