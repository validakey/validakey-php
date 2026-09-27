<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Envelope;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\WordPress\ConfigPrefixPanel;

require_once __DIR__ . '/WordPressOptionStubs.php';

final class ConfigPrefixPanelTest extends TestCase
{
    private const BASE_URL = 'https://example.validakey.host/v1';
    private const UUID = '4b844fdd-edc0-446c-b9ed-c8800eeb4a08';
    private const USER_APP_ID = '4edc8320-c9ac-4c35-a4c0-9466ebc29f42';
    private const HOST = 'test.example.com';

    protected function setUp(): void
    {
        $GLOBALS['validakey_test_wp_options'] = array();
        $GLOBALS['validakey_test_transients'] = array();
        $GLOBALS['validakey_test_is_admin'] = true;
        $GLOBALS['validakey_test_caps'] = array('manage_options');
    }

    public function testRenderUnconfigured(): void
    {
        $html = ConfigPrefixPanel::render(null, array(
            'echo' => false,
            'unconfigured_message' => 'Missing constants.',
        ));

        self::assertStringContainsString('Missing constants.', $html);
        self::assertStringNotContainsString('Account UUID', $html);
    }

    public function testRenderShowsUuidAndAppIdPrefixes(): void
    {
        $client = new ValidakeyClient(
            new ValidakeyConfig(
                baseUrl: self::BASE_URL,
                apiUUID: self::UUID,
                userAppId: self::USER_APP_ID,
                host: self::HOST,
            ),
            null,
            new InMemoryInstanceStore()
        );

        $html = ConfigPrefixPanel::render($client, array('echo' => false));

        self::assertStringContainsString('Account UUID', $html);
        self::assertStringContainsString('App ID', $html);
        self::assertStringContainsString(Envelope::prefix(self::UUID), $html);
        self::assertStringContainsString(Envelope::prefix(self::USER_APP_ID), $html);
        self::assertStringNotContainsString('Cleartext lookup prefixes', $html);
        self::assertStringNotContainsString(self::UUID, $html);
        self::assertStringNotContainsString(self::USER_APP_ID, $html);
        self::assertStringNotContainsString('<h2', $html);
        self::assertStringNotContainsString('<table', $html);
    }
}
