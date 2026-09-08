<?php
/**
 * WordPress admin_menu action that renders Menu subclasses declared in config.
 * Validates each menu class extends Menu and calls render when displayable.
 * Connects declarative menu config to wp admin page registration.
 *
 * @package    Framework
 * @subpackage Wordpress\Hooks\Actions
 * @since      1.0.0
 */
namespace Framework\Wordpress\Hooks\Actions;

defined('ABSPATH') || exit;

use Framework\Wordpress\Constants\HookTypes;
use Framework\Wordpress\BaseHook;
use Framework\Wordpress\Constants\HookNames;
use Framework\Wordpress\Menu;
use Exception;

use function Framework\config;
use function Framework\throw_if;

class RegisterAdminMenu extends BaseHook
{
    /**
     * Get the name.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_name()
    {
        return HookNames::ADMIN_MENU;
    }

    /**
     * Get the type.
     *
     * @return mixed
     *
     * @since 1.0.0
     */
    public function get_type()
    {
        return HookTypes::ACTION;
    }

    /**
     * Handle.
     *
     * @param mixed $args The positional arguments.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function handle(...$args)
    {
        $menus = config('menu', []);

        if (empty($menus)) {
            return;
        }

        foreach ($menus as $menu) {
            throw_if(
                !class_exists($menu) || !is_subclass_of($menu, Menu::class),
                sprintf('Menu class %s does not exist.', $menu),
                Exception::class
            );

            $menu_instance = new $menu();

            if ($menu_instance->is_displayable()) {
                $menu_instance->render();
            };
        }
    }
}
