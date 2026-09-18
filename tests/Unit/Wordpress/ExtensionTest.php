<?php

namespace Framework\Tests\Unit\Wordpress;

use Exception;
use Framework\Tests\Unit\TestCase;
use Framework\Wordpress\Extension;
use WP_Error;

class ExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->reset_plugin_globals();
    }

    protected function tearDown(): void
    {
        $this->reset_plugin_globals();

        parent::tearDown();
    }

    protected function reset_plugin_globals(): void
    {
        $GLOBALS['framework_test_plugins'] = [];
        $GLOBALS['framework_test_active_plugins'] = [];
        $GLOBALS['framework_test_activate_plugin_error'] = null;
        $GLOBALS['framework_test_delete_plugins_error'] = null;
        $GLOBALS['framework_test_plugin_upgrader_install_result'] = true;
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = false;
    }

    public function test_install_throws_for_empty_url(): void
    {
        $this->expectException(Exception::class);

        Extension::install('');
    }

    public function test_install_installs_without_activating(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = 'sample/sample.php';

        $result = Extension::install('https://example.com/plugin.zip', false);

        $this->assertTrue($result);
        $this->assertFalse(Extension::is_active('sample/sample.php'));
    }

    public function test_install_overwrites_by_default(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = 'sample/sample.php';

        Extension::install('https://example.com/plugin.zip', false);

        $this->assertTrue($GLOBALS['framework_test_plugin_upgrader_install_args']['overwrite_package']);
    }

    public function test_install_can_disable_overwrite(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = 'sample/sample.php';

        Extension::install('https://example.com/plugin.zip', false, false);

        $this->assertFalse($GLOBALS['framework_test_plugin_upgrader_install_args']['overwrite_package']);
    }

    public function test_install_installs_and_activates_by_default(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = 'sample/sample.php';

        $result = Extension::install('https://example.com/plugin.zip');

        $this->assertTrue($result);
        $this->assertTrue(Extension::is_active('sample/sample.php'));
    }

    public function test_install_throws_when_installer_returns_empty_result(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_install_result'] = false;

        $this->expectException(Exception::class);

        Extension::install('https://example.com/plugin.zip');
    }

    public function test_install_throws_when_installer_returns_wp_error(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_install_result'] = new WP_Error('install_failed', 'Install failed.');

        $this->expectExceptionMessage('Install failed.');

        Extension::install('https://example.com/plugin.zip');
    }

    public function test_install_throws_instead_of_reactivating_an_already_active_plugin(): void
    {
        $GLOBALS['framework_test_plugin_upgrader_plugin_info'] = 'sample/sample.php';
        $GLOBALS['framework_test_active_plugins'] = ['sample/sample.php'];

        $this->expectException(Exception::class);

        Extension::install('https://example.com/plugin.zip');
    }

    public function test_is_installed_returns_true_for_installed_plugin(): void
    {
        $GLOBALS['framework_test_plugins'] = ['sample/sample.php' => ['Name' => 'Sample']];

        $this->assertTrue(Extension::is_installed('sample/sample.php'));
    }

    public function test_is_installed_returns_false_for_missing_plugin(): void
    {
        $this->assertFalse(Extension::is_installed('sample/sample.php'));
    }

    public function test_is_active_returns_true_for_active_plugin(): void
    {
        $GLOBALS['framework_test_active_plugins'] = ['sample/sample.php'];

        $this->assertTrue(Extension::is_active('sample/sample.php'));
    }

    public function test_is_active_returns_false_for_inactive_plugin(): void
    {
        $this->assertFalse(Extension::is_active('sample/sample.php'));
    }

    public function test_activate_returns_true_on_success(): void
    {
        $result = Extension::activate('sample/sample.php');

        $this->assertTrue($result);
        $this->assertTrue(Extension::is_active('sample/sample.php'));
    }

    public function test_activate_throws_on_wp_error(): void
    {
        $GLOBALS['framework_test_activate_plugin_error'] = new WP_Error('activate_failed', 'Activation failed.');

        $this->expectExceptionMessage('Activation failed.');

        Extension::activate('sample/sample.php');
    }

    public function test_deactivate_removes_plugin_from_active_list(): void
    {
        $GLOBALS['framework_test_active_plugins'] = ['sample/sample.php'];

        $result = Extension::deactivate('sample/sample.php');

        $this->assertTrue($result);
        $this->assertFalse(Extension::is_active('sample/sample.php'));
    }

    public function test_remove_deletes_installed_inactive_plugin(): void
    {
        $GLOBALS['framework_test_plugins'] = ['sample/sample.php' => ['Name' => 'Sample']];

        $result = Extension::remove('sample/sample.php');

        $this->assertTrue($result);
        $this->assertFalse(Extension::is_installed('sample/sample.php'));
    }

    public function test_remove_throws_for_active_plugin(): void
    {
        $GLOBALS['framework_test_plugins'] = ['sample/sample.php' => ['Name' => 'Sample']];
        $GLOBALS['framework_test_active_plugins'] = ['sample/sample.php'];

        $this->expectException(Exception::class);

        Extension::remove('sample/sample.php');
    }

    public function test_remove_throws_on_wp_error(): void
    {
        $GLOBALS['framework_test_plugins'] = ['sample/sample.php' => ['Name' => 'Sample']];
        $GLOBALS['framework_test_delete_plugins_error'] = new WP_Error('delete_failed', 'Removal failed.');

        $this->expectExceptionMessage('Removal failed.');

        Extension::remove('sample/sample.php');
    }
}
