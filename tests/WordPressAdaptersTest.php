<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\WordPress\OptionsInstanceStore;
use Validakey\WordPress\OptionsTokenStore;
use Validakey\WordPress\PluginClientFactory;

require_once __DIR__ . '/WordPressOptionStubs.php';
require_once __DIR__ . '/Fixtures/ValidakeyWpConstants.php';

final class WordPressAdaptersTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['validakey_test_wp_options'] = array();
        $GLOBALS['validakey_test_wp_update_autoload'] = array();
        $GLOBALS['validakey_test_home_url'] = 'https://shop.example.com';
    }

    public function testOptionsInstanceStoreRejectsEmptyOptionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionsInstanceStore('   ');
    }

    public function testOptionsInstanceStoreRoundTripsAndDisablesAutoload(): void
    {
        $store = new OptionsInstanceStore('myplugin_validakey_instances');

        self::assertNull($store->get('app|subject|fp'));

        $store->set('app|subject|fp', 'instance-id-1');
        self::assertSame('instance-id-1', $store->get('app|subject|fp'));
        self::assertSame(
            array('app|subject|fp' => 'instance-id-1'),
            $GLOBALS['validakey_test_wp_options']['myplugin_validakey_instances']
        );
        self::assertFalse(
            $GLOBALS['validakey_test_wp_update_autoload']['myplugin_validakey_instances']
        );

        $store->set('other', 'instance-id-2');
        self::assertSame('instance-id-1', $store->get('app|subject|fp'));
        self::assertSame('instance-id-2', $store->get('other'));

        $store->forget('app|subject|fp');
        self::assertNull($store->get('app|subject|fp'));
        self::assertSame('instance-id-2', $store->get('other'));

        $store->forget('missing');
        self::assertSame(
            array('other' => 'instance-id-2'),
            $GLOBALS['validakey_test_wp_options']['myplugin_validakey_instances']
        );
    }

    public function testOptionsInstanceStoreTreatsCorruptOptionAsEmpty(): void
    {
        $GLOBALS['validakey_test_wp_options']['broken'] = 'not-an-array';
        $store = new OptionsInstanceStore('broken');

        self::assertNull($store->get('k'));
        $store->set('k', 'v');
        self::assertSame('v', $store->get('k'));
    }

    public function testConfigArrayFromConstantsReadsNamespacedConsts(): void
    {
        $config = PluginClientFactory::configArrayFromConstants('Validakey\\Tests\\Fixtures');

        self::assertSame('https://example.validakey.host/v1', $config['base_url']);
        self::assertSame('11111111-1111-4111-8111-111111111111', $config['api_uuid']);
        self::assertSame('a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b', $config['user_app_id']);
        self::assertSame('www.example.com', $config['host']);
        self::assertSame(15.0, $config['timeout']);
        self::assertArrayNotHasKey('api_pkey', $config);
    }

    public function testPluginClientFactoryIsConfiguredFromConstants(): void
    {
        self::assertFalse(PluginClientFactory::isConfigured(array()));
        self::assertTrue(PluginClientFactory::isConfigured(null, 'Validakey\\Tests\\Fixtures'));
    }

    public function testPluginClientFactoryConfigFromArrayMapsKeys(): void
    {
        $config = PluginClientFactory::configFromArray(array(
            'base_url' => 'https://example.validakey.host/v1/',
            'api_uuid' => '11111111-1111-4111-8111-111111111111',
            'user_app_id' => 'a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b',
            'api_pkey' => '0123456789abcdef0123456789abcdef0123456789abcdef',
            'subject' => 'customer-42',
            'host' => 'www.example.com',
            'timeout' => 15,
        ));

        self::assertInstanceOf(ValidakeyConfig::class, $config);
        self::assertSame('https://example.validakey.host/v1/', $config->baseUrl);
        self::assertSame('0123456789abcdef0123456789abcdef0123456789abcdef', $config->apiPKey);
        self::assertSame('customer-42', $config->subject);
        self::assertSame('www.example.com', $config->host);
        self::assertSame(15.0, $config->timeout);
        self::assertTrue($config->hasPKey());
    }

    public function testPluginClientFactoryMakeFromConstants(): void
    {
        $client = PluginClientFactory::make(
            null,
            'roundpeg',
            null,
            'Validakey\\Tests\\Fixtures',
        );

        self::assertInstanceOf(ValidakeyClient::class, $client);
        self::assertSame(
            'roundpeg_validakey_instances',
            PluginClientFactory::optionNameForSlug('roundpeg')
        );

        $store = new OptionsInstanceStore('roundpeg_validakey_instances');
        $store->set('probe', 'stored');
        self::assertSame('stored', $store->get('probe'));
    }

    public function testPluginClientFactoryMakeAcceptsOptionNameOverride(): void
    {
        PluginClientFactory::make(
            PluginClientFactory::configArrayFromConstants('Validakey\\Tests\\Fixtures'),
            'roundpeg',
            null,
            null,
            'custom_validakey_instances',
        );

        $store = new OptionsInstanceStore('custom_validakey_instances');
        $store->set('probe', 'stored');
        self::assertSame('stored', $store->get('probe'));
    }

    public function testPluginSlugGuessFromExplicitPluginPath(): void
    {
        $slug = PluginClientFactory::pluginSlug_Guess(
            '/var/www/site/wp-content/plugins/roundpeg/includes/class-roundpeg-validakey.php'
        );

        self::assertSame('roundpeg', $slug);
    }

    public function testPluginSlugGuessUsesWpPluginDirWhenDefined(): void
    {
        if (! defined('WP_PLUGIN_DIR')) {
            define('WP_PLUGIN_DIR', '/srv/wordpress/wp-content/plugins');
        }

        $slug = PluginClientFactory::pluginSlug_Guess(
            WP_PLUGIN_DIR . '/acme-licensing/src/Client.php'
        );

        self::assertSame('acme-licensing', $slug);
    }

    public function testPluginSlugGuessThrowsOutsidePlugins(): void
    {
        $this->expectException(\RuntimeException::class);
        PluginClientFactory::pluginSlug_Guess('/tmp/not-a-plugin/file.php');
    }

    public function testCallerNamespaceGuessFromNamespacedClass(): void
    {
        require_once __DIR__ . '/Fixtures/NamespaceProbe.php';

        self::assertSame(
            'Validakey\\Tests\\Fixtures',
            \Validakey\Tests\Fixtures\NamespaceProbe::guessNamespace()
        );
    }

    public function testConfigArrayFromConstantsGuessesCallerNamespace(): void
    {
        require_once __DIR__ . '/Fixtures/NamespaceProbe.php';

        $config = \Validakey\Tests\Fixtures\NamespaceProbe::configFromGuessedNamespace();

        self::assertSame('https://example.validakey.host/v1', $config['base_url']);
        self::assertSame('a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b', $config['user_app_id']);
    }

    public function testPluginClientFactoryMakeThrowsWhenIncomplete(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Validakey is not configured; missing or empty: VALIDAKEY_API_UUID, VALIDAKEY_USER_APP_ID.');
        PluginClientFactory::make(array('base_url' => 'https://x'), 'opt');
    }

    public function testMissingRequiredConstantsListsNamespacedNames(): void
    {
        $missing = PluginClientFactory::missingRequiredConstants(array(), 'YourPlugin');

        self::assertSame(
            array(
                'YourPlugin\\VALIDAKEY_BASE_URL',
                'YourPlugin\\VALIDAKEY_API_UUID',
                'YourPlugin\\VALIDAKEY_USER_APP_ID',
            ),
            $missing
        );
    }

    public function testOptionsTokenStoreRoundTripsAndDisablesAutoload(): void
    {
        $store = new OptionsTokenStore('myplugin_validakey_tokens');

        self::assertNull($store->get('app|subject|fp'));

        $store->set('app|subject|fp', 'TOKEN-1');
        self::assertSame('TOKEN-1', $store->get('app|subject|fp'));
        self::assertSame(
            array('app|subject|fp' => 'TOKEN-1'),
            $GLOBALS['validakey_test_wp_options']['myplugin_validakey_tokens']
        );
        self::assertFalse(
            $GLOBALS['validakey_test_wp_update_autoload']['myplugin_validakey_tokens']
        );

        $store->forget('app|subject|fp');
        self::assertNull($store->get('app|subject|fp'));
    }

    public function testOptionsTokenStoreRejectsEmptyOptionName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new OptionsTokenStore('   ');
    }

    public function testTokenOptionNameForSlug(): void
    {
        self::assertSame(
            'roundpeg_validakey_tokens',
            PluginClientFactory::tokenOptionNameForSlug('roundpeg')
        );
    }

    public function testPluginClientFactoryLicenseDefaultsSubjectToSiteHost(): void
    {
        $license = PluginClientFactory::license(
            CreateTokenRequest::free(),
            null,
            'roundpeg',
            null,
            'Validakey\\Tests\\Fixtures',
        );

        self::assertInstanceOf(License::class, $license);
        self::assertSame('shop.example.com', $license->subject());
        self::assertFalse($license->hasToken());

        $store = new OptionsTokenStore('roundpeg_validakey_tokens');
        $store->set('probe', 'stored');
        self::assertSame('stored', $store->get('probe'));
    }

    public function testPluginClientFactoryLicenseKeepsConfiguredSubject(): void
    {
        $config = PluginClientFactory::configArrayFromConstants('Validakey\\Tests\\Fixtures');
        $config['subject'] = 'customer-42';

        $license = PluginClientFactory::license(
            CreateTokenRequest::free(),
            $config,
            'roundpeg',
        );

        self::assertSame('customer-42', $license->subject());
    }
}
