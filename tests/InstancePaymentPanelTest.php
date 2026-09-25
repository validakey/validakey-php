<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Request\AttachCardRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\WordPress\InstancePaymentPanel;

require_once __DIR__ . '/WordPressOptionStubs.php';

final class InstancePaymentPanelTest extends TestCase
{
    private const BASE_URL = 'https://example.validakey.host/v1';
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PKEY = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const USER_APP_ID = 'a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b';
    private const HOST = 'test.example.com';

    protected function setUp(): void
    {
        $GLOBALS['validakey_test_wp_options'] = array();
        $GLOBALS['validakey_test_transients'] = array();
        $GLOBALS['validakey_test_is_admin'] = true;
        $GLOBALS['validakey_test_caps'] = array('manage_options');
        $GLOBALS['validakey_test_settings_errors'] = array();
    }

    public function testRenderUnconfigured(): void
    {
        $html = InstancePaymentPanel::render(null, array('echo' => false));

        self::assertStringContainsString('validakey-instance-payment', $html);
        self::assertStringContainsString('Payment method', $html);
        self::assertStringContainsString('Validakey is not configured.', $html);
    }

    public function testRenderShowsHostedLinkWhenNoSquareIds(): void
    {
        $html = InstancePaymentPanel::render($this->client(), array('echo' => false));

        self::assertStringContainsString('Square checkout is not configured', $html);
        self::assertStringContainsString('name="' . InstancePaymentPanel::DEFAULT_LINK_ACTION . '"', $html);
        self::assertStringNotContainsString('validakey-ie-card-form', $html);
    }

    public function testRenderShowsCardFormWhenApplicationIdPresent(): void
    {
        $html = InstancePaymentPanel::render($this->client(true), array('echo' => false));

        self::assertStringContainsString('No card on file', $html);
        self::assertStringContainsString('validakey-ie-card-form', $html);
        self::assertStringContainsString('name="' . InstancePaymentPanel::DEFAULT_LINK_ACTION . '"', $html);
    }

    public function testRenderShowsCardOnFileAfterAttach(): void
    {
        $client = $this->client();
        $client->attachInstanceCard(new AttachCardRequest(sourceId: 'cnon:card-nonce-ok'));

        $html = InstancePaymentPanel::render($client, array('echo' => false));

        self::assertStringContainsString('Card on file:', $html);
        self::assertStringContainsString('VISA •••• 4242', $html);
        self::assertStringContainsString('name="' . InstancePaymentPanel::DEFAULT_DETACH_ACTION . '"', $html);
        self::assertStringNotContainsString('validakey-ie-card-form', $html);
    }

    private function client(bool $withSquareIds = false): ValidakeyClient
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        if ($withSquareIds) {
            $server->squareApplicationId = 'sandbox-sq0idb-test';
            $server->squareLocationId = 'LTEST';
            $server->squareSandbox = true;
        }

        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/p/' => $server->paymentResponder(),
        ));

        return new ValidakeyClient(
            new ValidakeyConfig(
                baseUrl: self::BASE_URL,
                apiUUID: self::UUID,
                userAppId: self::USER_APP_ID,
                apiPKey: self::PKEY,
                subject: 'shop.example.com',
                host: self::HOST,
            ),
            $transport,
            new InMemoryInstanceStore()
        );
    }
}
