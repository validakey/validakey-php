<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Instance\InMemoryTokenStore;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\Response\LicenseStatus;
use Validakey\Response\TokenVerifyResponse;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\WordPress\LicensePanel;

require_once __DIR__ . '/WordPressOptionStubs.php';

final class LicensePanelTest extends TestCase
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
    }

    public function testRenderShowsNotGrantedAndRequestButton(): void
    {
        $license = $this->license();

        $html = LicensePanel::render($license, array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('Not granted', $html);
        self::assertStringContainsString('name="validakey_license_request"', $html);
        self::assertStringContainsString('shop.example.com', $html);
        self::assertStringNotContainsString('Token Locator', $html);
    }

    public function testRenderShowsTokenLocatorAfterGrant(): void
    {
        $license = $this->license();
        $license->request();

        $html = LicensePanel::render($license, array(
            'echo' => false,
            'configured' => true,
        ));

        self::assertStringContainsString('Valid', $html);
        self::assertStringContainsString('Never', $html);
        self::assertStringContainsString('Token Locator', $html);
        self::assertStringContainsString((string) $license->tokenLocator(), $html);
        self::assertStringNotContainsString('name="validakey_license_request"', $html);
        // Locator may include the short fake-server token head; the full secret
        // must not appear as its own cell value.
        self::assertStringNotContainsString(
            '<td>' . esc_html((string) $license->token()) . '</td>',
            $html
        );
    }

    public function testRenderUnconfiguredDoesNotNeedALicense(): void
    {
        $html = LicensePanel::render(null, array(
            'echo' => false,
            'configured' => false,
            'unconfigured_message' => 'Missing constants.',
        ));

        self::assertStringContainsString('Missing constants.', $html);
        self::assertStringNotContainsString('Request', $html);
    }

    public function testApiNoticeExplainsHtmlReplies(): void
    {
        $notice = LicensePanel::apiNotice(new ApiException(
            'Validakey API returned a malformed (non-JSON) response (HTTP 200).',
            'invalid_response',
            200,
            '<!DOCTYPE html><html><title>dev.Validakey.com</title></html>'
        ));

        self::assertStringContainsString('HTTP 200', $notice);
        self::assertStringContainsString('HTML page', $notice);
        self::assertStringContainsString('dev.Validakey.com', $notice);
    }

    public function testPaymentNoticeDistinguishesIeCard(): void
    {
        $notice = LicensePanel::paymentNotice(new PaymentRequiredException(
            'Card required',
            'ie_card_required',
            402
        ));

        self::assertStringContainsString('payment card for the site', $notice);
    }

    public function testGrantLabelMapsReasons(): void
    {
        self::assertSame(
            'Not granted',
            LicensePanel::grantLabel(LicenseStatus::none('shop.example.com'))
        );
        self::assertSame(
            'Revoked',
            LicensePanel::grantLabel(LicenseStatus::fromVerify(
                'shop.example.com',
                new TokenVerifyResponse(array('valid' => false, 'reason' => 'revoked'))
            ))
        );
    }

    private function license(): License
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/m/' => $server->tokenResponder(),
            'POST ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
            'DELETE ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
        ));
        $config = new ValidakeyConfig(
            baseUrl: self::BASE_URL,
            apiUUID: self::UUID,
            userAppId: self::USER_APP_ID,
            apiPKey: self::PKEY,
            subject: 'shop.example.com',
            host: self::HOST,
        );
        $client = new ValidakeyClient($config, $transport, new InMemoryInstanceStore());

        return new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());
    }
}
