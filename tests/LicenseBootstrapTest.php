<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\WordPress\LicenseBootstrap;
use Validakey\WordPress\LicenseScheduler;
use Validakey\WordPress\OptionsLicenseCheckStore;
use Validakey\WordPress\PluginClientFactory;

require_once __DIR__ . '/WordPressOptionStubs.php';
require_once __DIR__ . '/Fixtures/ValidakeyWpConstants.php';

final class LicenseBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        LicenseBootstrap::unregister();
        $GLOBALS['validakey_test_wp_options'] = array();
        $GLOBALS['validakey_test_wp_update_autoload'] = array();
        $GLOBALS['validakey_test_transients'] = array();
        $GLOBALS['validakey_test_actions'] = array();
        $GLOBALS['validakey_test_cron'] = array();
        $GLOBALS['validakey_test_cron_recurrence'] = array();
        $GLOBALS['validakey_test_submenus'] = array();
        $GLOBALS['validakey_test_home_url'] = 'https://shop.example.com';
        $GLOBALS['validakey_test_is_admin'] = true;
        $GLOBALS['validakey_test_caps'] = array('manage_options');
        $_GET = array();
        $_POST = array();
    }

    protected function tearDown(): void
    {
        LicenseBootstrap::unregister();
    }

    public function testRegisterWiresCronAndExposesAllows(): void
    {
        LicenseBootstrap::register(array(
            'plugin_slug' => 'roundpeg',
            'constants_namespace' => 'Validakey\\Tests\\Fixtures',
            'settings_page' => 'roundpeg-settings',
            'redirect_url' => 'https://shop.example.com/wp-admin/options-general.php?page=roundpeg-settings&roundpeg_tab=license',
            'admin_notice' => false,
            'add_submenu' => false,
        ));

        self::assertTrue(LicenseBootstrap::isConfigured());
        self::assertFalse(LicenseBootstrap::allows());
        self::assertInstanceOf(License::class, LicenseBootstrap::license());
        self::assertArrayHasKey('roundpeg_validakey_revalidate', $GLOBALS['validakey_test_cron']);
        self::assertSame(
            LicenseScheduler::DEFAULT_RECURRENCE,
            $GLOBALS['validakey_test_cron_recurrence']['roundpeg_validakey_revalidate']
        );
        self::assertArrayHasKey('admin_init', $GLOBALS['validakey_test_actions']);
    }

    public function testAddSubmenuRegistersUnderParent(): void
    {
        LicenseBootstrap::register(array(
            'plugin_slug' => 'demo',
            'constants_namespace' => 'Validakey\\Tests\\Fixtures',
            'add_submenu' => true,
            'parent_slug' => 'options-general.php',
            'redirect_url' => 'https://shop.example.com/wp-admin/options-general.php?page=demo-license',
            'admin_notice' => false,
        ));

        $boot = LicenseBootstrap::instance();
        self::assertNotNull($boot);
        $boot->onAdminMenu();

        self::assertCount(1, $GLOBALS['validakey_test_submenus']);
        self::assertSame('demo-license', $GLOBALS['validakey_test_submenus'][0]['menu_slug']);
        self::assertSame('options-general.php', $GLOBALS['validakey_test_submenus'][0]['parent_slug']);
    }

    public function testRenderPanelWhenUnconfigured(): void
    {
        LicenseBootstrap::register(array(
            'plugin_slug' => 'empty',
            'constants_namespace' => 'Validakey\\Tests\\MissingNs',
            'settings_page' => 'empty',
            'redirect_url' => 'https://example.test/',
            'schedule' => false,
            'admin_notice' => false,
        ));

        self::assertFalse(LicenseBootstrap::isConfigured());
        $html = LicenseBootstrap::renderPanel(array('echo' => false));
        self::assertStringContainsString('not configured', strtolower($html));
    }

    public function testRegisterWithoutPluginSlugUsesGuess(): void
    {
        // Outside wp-content/plugins the guess cannot resolve a slug.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot guess plugin slug');

        LicenseBootstrap::register(array(
            'constants_namespace' => 'Validakey\\Tests\\Fixtures',
            'redirect_url' => 'https://example.test/',
            'schedule' => false,
            'admin_notice' => false,
            'add_submenu' => false,
        ));
    }

    public function testCheckOptionNameForSlug(): void
    {
        self::assertSame(
            'roundpeg_validakey_license_checks',
            PluginClientFactory::checkOptionNameForSlug('roundpeg')
        );
        LicenseBootstrap::register(array(
            'plugin_slug' => 'roundpeg',
            'constants_namespace' => 'Validakey\\Tests\\Fixtures',
            'redirect_url' => 'https://example.test/',
            'schedule' => false,
            'admin_notice' => false,
            'add_submenu' => false,
            'settings_page' => 'roundpeg-settings',
        ));
        LicenseBootstrap::license();
        $store = new OptionsLicenseCheckStore('roundpeg_validakey_license_checks');
        self::assertNull($store->get('missing'));
    }

    public function testDeactivateClearsCron(): void
    {
        LicenseBootstrap::register(array(
            'plugin_slug' => 'roundpeg',
            'constants_namespace' => 'Validakey\\Tests\\Fixtures',
            'redirect_url' => 'https://example.test/',
            'admin_notice' => false,
        ));
        self::assertArrayHasKey('roundpeg_validakey_revalidate', $GLOBALS['validakey_test_cron']);

        LicenseBootstrap::deactivate('roundpeg');
        self::assertArrayNotHasKey('roundpeg_validakey_revalidate', $GLOBALS['validakey_test_cron']);
    }

    public function testFactoryLicenseUsesCheckStore(): void
    {
        $license = PluginClientFactory::license(
            CreateTokenRequest::free(),
            null,
            'roundpeg',
            null,
            'Validakey\\Tests\\Fixtures',
        );

        self::assertFalse($license->allows());
        self::assertNull($license->lastCheck());
    }
}
