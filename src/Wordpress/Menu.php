<?php
/**
 * Base class for declaring WordPress admin menu pages with title, capability, slug, and icon properties.
 * Supports main menu and submenu types with a render method calling add_menu_page or add_submenu_page.
 * Subclasses define is_displayable for conditional visibility.
 *
 * @package    Framework
 * @subpackage Wordpress
 * @since      1.0.0
 */
namespace Framework\Wordpress;

defined('ABSPATH') || exit;

use Framework\Wordpress\Constants\MenuTypes;
use Framework\Supports\Arr;

use function Framework\collection;
use function Framework\throw_if;

class Menu
{
    /**
     * The page menu type i.e. main menu or submenu
     *
     * @var MenuTypes
     *
     * @since 1.0.0
     */
    protected $menu_type = MenuTypes::MAIN_MENU;

    /**
     * The page title
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $page_title = null;

    /**
     * The menu title
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $menu_title = null;

    /**
     * The capabilities
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $capabilities = null;

    /**
     * The menu slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $menu_slug = null;

    /**
     * The callback
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $callback = '__return_false';

    /**
     * The icon url
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $icon_url = null;

    /**
     * The position
     *
     * @var int
     *
     * @since 1.0.0
     */
    protected $position = null;

    /**
     * The parent slug
     *
     * @var string
     *
     * @since 1.0.0
     */
    protected $parent_slug = null;


    /**
     * Create a new instance.
     *
     * @return void
     *
     * @throws \Exception
     *
     * @since 1.0.0
     */
    public function __construct()
    {
        throw_if(!$this->check_required_properties(), 'Missing required properties for making a menu item');
    }

    /**
     * Determine whether the displayable.
     *
     * @return bool
     *
     * @since 1.0.0
     */
    public function is_displayable()
    {
        return true;
    }

    /**
     * Check required properties.
     *
     * @return void
     *
     * @since 1.0.0
     */
    protected function check_required_properties()
    {
        $properties = [
            $this->page_title,
            $this->menu_title,
            $this->capabilities,
            $this->menu_slug,
        ];

        if (MenuTypes::SUB_MENU === $this->menu_type) {
            $properties[] = $this->parent_slug;
        }

        return collection($properties)->every(function ($property) {
            return !empty($property);
        });
    }

    /**
     * Render the menu
     *
     * @return void
     *
     * @since 1.0.0
     */
    public function render()
    {
        if ($this->menu_type === MenuTypes::MAIN_MENU) {
            add_menu_page(
                $this->page_title,
                $this->menu_title,
                $this->capabilities,
                $this->menu_slug,
                $this->callback,
                $this->icon_url,
                $this->position
            );
        } else {
            add_submenu_page(
                $this->parent_slug,
                $this->page_title,
                $this->menu_title,
                $this->capabilities,
                $this->menu_slug,
                $this->callback,
                $this->position
            );
        }
    }
}
