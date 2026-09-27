<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Instance\InMemoryTokenStore;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\WordPress\LicensePanel;
use Validakey\WordPress\LicensePurchasePanel;

require_once __DIR__ . '/WordPressOptionStubs.php';

final class LicensePurchasePanelTest extends TestCase
{
    private const BASE_URL = 'https://example.validakey.host/v1';
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PKEY = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const USER_APP_ID = 'a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b';
    private const HOST = 'test.example.com';

    protected function setUp(): void
    {
        $GLOBALS['validakey_test_wp_options'] = array(
            'date_format' => 'Y-m-d',
            'time_format' => 'H:i',
        );
        $GLOBALS['validakey_test_transients'] = array();
        $GLOBALS['validakey_test_home_url'] = 'https://shop.example.com';
        $GLOBALS['validakey_test_is_admin'] = true;
        $GLOBALS['validakey_test_caps'] = array('manage_options');
        $GLOBALS['validakey_test_settings_errors'] = array();
    }

    public function testRenderUnconfigured(): void
    {
        $html = LicensePurchasePanel::render(null, array(
            'echo' => false,
            'configured' => false,
            'unconfigured_message' => 'Missing constants.',
        ));

        self::assertStringContainsString('Missing constants.', $html);
        self::assertStringNotContainsString('Purchase license', $html);
        self::assertStringNotContainsString('name="' . LicensePanel::DEFAULT_ACTION . '"', $html);
    }

    public function testRenderUngrantedWithoutPolicyShowsRequest(): void
    {
        $html = LicensePurchasePanel::render($this->license(), array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('License terms', $html);
        self::assertStringContainsString('No enforced policy found for this app id', $html);
        self::assertStringContainsString('Request license', $html);
        self::assertStringContainsString('name="' . LicensePanel::DEFAULT_ACTION . '"', $html);
        self::assertStringNotContainsString('Payment method', $html);
        self::assertStringNotContainsString('Save card', $html);
        self::assertStringContainsString('data-validakey-require-card="0"', $html);
        self::assertStringNotContainsString('Open hosted payment link', $html);
        self::assertStringNotContainsString('validakey_instance_payment_link', $html);
    }

    public function testRenderWhenPolicyEndpointMissingExplains(): void
    {
        $html = LicensePurchasePanel::render($this->license(false, false), array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('does not expose mint policy', $html);
        self::assertStringContainsString('Request license', $html);
        self::assertStringNotContainsString('Payment method', $html);
    }

    public function testRenderUngrantedWithPricedPolicyShowsPurchase(): void
    {
        $html = LicensePurchasePanel::render($this->license(true), array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('License terms', $html);
        self::assertStringContainsString('$19.99', $html);
        self::assertStringContainsString('1 day', $html);
        self::assertStringContainsString('Fixed', $html);
        self::assertStringContainsString('Purchase license', $html);
        self::assertStringContainsString('validakey-ie-card-form', $html);
        self::assertStringNotContainsString('Save card', $html);
        self::assertStringContainsString('data-validakey-require-card="1"', $html);
    }

    public function testRenderGrantedShowsStatusNotPurchase(): void
    {
        $license = $this->license();
        $license->request();

        $html = LicensePurchasePanel::render($license, array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('Valid', $html);
        self::assertStringContainsString('Token Locator', $html);
        self::assertStringContainsString('name="' . LicensePanel::DEFAULT_DELETE_ACTION . '"', $html);
        self::assertStringNotContainsString('Purchase license', $html);
        self::assertStringNotContainsString('License terms', $html);
    }

    private function license(bool $pricedPolicy = false, bool $policyEndpoint = true): License
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        if ($pricedPolicy) {
            $server->mintPolicy = array(
                'enabled' => true,
                'defaults' => array(
                    'basis_cents' => 1999,
                    'duration' => 86400,
                ),
                'limits' => array(
                    'basis_cents_min' => 1999,
                    'basis_cents_max' => 1999,
                    'allow_no_expiry' => false,
                ),
            );
            $server->squareApplicationId = 'sandbox-sq0idb-test';
            $server->squareLocationId = 'LTEST';
        }

        $routes = array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/m/' => $server->tokenResponder(),
            'POST ' . self::BASE_URL . '/p/' => $server->paymentResponder(),
            'POST ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
            'DELETE ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
        );
        if ($policyEndpoint) {
            $routes['POST ' . self::BASE_URL . '/policy/'] = $server->policyResponder();
        } else {
            $routes['POST ' . self::BASE_URL . '/policy/'] = new MockResponse(404, (string) json_encode(array(
                'ERROR' => 'Invalid request.',
            )));
        }

        $transport = new MockHttpTransport($routes);

        $client = new ValidakeyClient(
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

        return new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());
    }
}
