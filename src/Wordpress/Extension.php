<?php
/**
 * Installs, queries, and manages the lifecycle of WordPress plugins from a single framework API.
 * Wraps Plugin_Upgrader and WordPress's core plugin functions so consuming plugins don't each
 * reimplement add-on installation against WordPress internals.
 *
 * @package    Framework
 * @subpackage Wordpress
 * @since      1.0.0
 */
namespace Framework\Wordpress;

defined('ABSPATH') || exit;

use Automatic_Upgrader_Skin;
use Exception;
use Plugin_Upgrader;

use function Framework\throw_anyway;
use function Framework\throw_if;

class Extension
{
    /**
     * Install a plugin from a ZIP URL.
     *
     * $overwrite defaults to true so a retry can recover from an earlier install that copied its
     * files but failed before activation. WordPress does not expose the plugin's basename until
     * after extraction, so this cannot check whether an existing installation is active before
     * overwriting it — it only refuses to reactivate a plugin found to already be active once the
     * overwrite has already happened.
     *
     * @param string $url The URL of the ZIP file.
     * @param bool $activate Whether to activate the plugin after installation.
     * @param bool $overwrite Whether to overwrite an existing installation at the destination.
     *
     * @return bool
     *
     * @throws Exception If the plugin installation or activation fails.
     *
     * @since 1.0.0
     */
    public static function install(string $url, bool $activate = true, bool $overwrite = true)
    {
        throw_if(empty($url), 'No ZIP URL provided.');

        if (!class_exists(Plugin_Upgrader::class)) {
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            include_once ABSPATH . 'wp-admin/includes/file.php';
            include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        }

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        $result = $upgrader->install($url, ['overwrite_package' => $overwrite]);

        throw_if(empty($result), 'Plugin installation failed.');

        if (is_wp_error($result)) {
            throw_anyway($result->get_error_message());
        }

        if (!$activate) {
            return true;
        }

        $plugin_path = $upgrader->plugin_info();

        throw_if(!$plugin_path, 'Could not determine plugin path.');

        return static::activate($plugin_path);
    }

    /**
     * Check whether a plugin is installed.
     *
     * @param string $plugin_basename The plugin basename, e.g. "plugin-dir/plugin-file.php".
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is_installed(string $plugin_basename)
    {
        static::load_plugin_functions();

        return array_key_exists($plugin_basename, get_plugins());
    }

    /**
     * Check whether a plugin is active.
     *
     * @param string $plugin_basename The plugin basename, e.g. "plugin-dir/plugin-file.php".
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function is_active(string $plugin_basename)
    {
        static::load_plugin_functions();

        return is_plugin_active($plugin_basename);
    }

    /**
     * Activate an installed plugin.
     *
     * @param string $plugin_basename The plugin basename, e.g. "plugin-dir/plugin-file.php".
     *
     * @return bool
     *
     * @throws Exception If activation fails.
     *
     * @since 1.0.0
     */
    public static function activate(string $plugin_basename)
    {
        static::load_plugin_functions();

        $result = activate_plugin($plugin_basename);

        if (is_wp_error($result)) {
            throw_anyway($result->get_error_message());
        }

        return true;
    }

    /**
     * Deactivate an active plugin.
     *
     * @param string $plugin_basename The plugin basename, e.g. "plugin-dir/plugin-file.php".
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public static function deactivate(string $plugin_basename)
    {
        static::load_plugin_functions();

        deactivate_plugins($plugin_basename);

        return true;
    }

    /**
     * Remove an installed plugin's files.
     *
     * Refuses to remove a currently active plugin rather than deactivating it as a side effect —
     * callers must deactivate() first.
     *
     * @param string $plugin_basename The plugin basename, e.g. "plugin-dir/plugin-file.php".
     *
     * @return bool
     *
     * @throws Exception If the plugin is active, or removal fails.
     *
     * @since 1.0.0
     */
    public static function remove(string $plugin_basename)
    {
        throw_if(static::is_active($plugin_basename), 'Cannot remove an active plugin. Deactivate it first.');

        static::load_plugin_functions();

        $result = delete_plugins([$plugin_basename]);

        if (is_wp_error($result)) {
            throw_anyway($result->get_error_message());
        }

        return true;
    }

    /**
     * Load WordPress's plugin management functions if they are not already available.
     *
     * These (get_plugins(), is_plugin_active(), activate_plugin(), deactivate_plugins(), and
     * delete_plugins()) are only autoloaded within the wp-admin request lifecycle.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected static function load_plugin_functions()
    {
        if (!function_exists('get_plugins')) {
            include_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
    }
}
