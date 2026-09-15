<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Exception\LicenseRequiredException;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Instance\InMemoryLicenseCheckStore;
use Validakey\Instance\InMemoryTokenStore;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\Response\LicenseSnapshot;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;

final class LicenseTest extends TestCase
{
    private const BASE_URL = 'https://example.validakey.host/v1';
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PKEY = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const USER_APP_ID = 'a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b';
    private const HOST = 'test.example.com';

    public function testFreeRequestOmitsPriceAndAsksForNoExpiry(): void
    {
        $payload = CreateTokenRequest::free()->toArray();

        self::assertSame(0, $payload['duration']);
        self::assertTrue($payload['no_expiry']);
        self::assertArrayNotHasKey('basis_cents', $payload);
        self::assertArrayNotHasKey('amount', $payload);
        self::assertArrayNotHasKey('cost_USD', $payload);
    }

    public function testStatusWithoutAStoredTokenDoesNotCallTheApi(): void
    {
        [$client, $transport] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        $status = $license->status();

        self::assertFalse($status->hasToken);
        self::assertFalse($status->isGranted());
        self::assertSame('none', $status->reason());
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $status->subject
        );
        self::assertCount(0, $transport->requests(), 'status() with no token must stay local.');
    }

    public function testRequestMintsPersistsAndVerifies(): void
    {
        [$client, , $server] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        $minted = $license->request();

        self::assertSame('TOKEN-1', $minted->token);
        self::assertSame('TOKEN-1', $license->token());
        self::assertTrue($license->allows(), 'request() must write a granted snapshot for allows().');
        self::assertTrue($license->isGranted());
        self::assertTrue($license->status()->isGranted());
        self::assertNull($license->status()->expiresAt());
        self::assertCount(1, $server->tokenRequests);
        self::assertTrue($server->tokenRequests[0]['payload']['no_expiry']);
    }

    public function testAllowsUsesSnapshotWithoutCallingTheApi(): void
    {
        [$client, $transport] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        self::assertFalse($license->allows());
        $license->request();
        $before = count($transport->requests());

        self::assertTrue($license->allows());
        self::assertCount($before, $transport->requests(), 'allows() must stay local.');
    }

    public function testFailClosedDeniesStaleGrantedSnapshot(): void
    {
        [$client] = $this->sealedClient();
        $checks = new InMemoryLicenseCheckStore();
        $license = new License(
            $client,
            new InMemoryTokenStore(),
            CreateTokenRequest::free(),
            $checks,
            revalidateInterval: 60,
            failClosed: true,
        );

        $license->request();
        $snap = $license->lastCheck();
        self::assertNotNull($snap);
        $checks->set($client->storeKey(), new LicenseSnapshot(
            granted: true,
            checkedAt: time() - 120,
            expiresAt: null,
            reason: '',
            subject: $license->subject(),
        ));

        self::assertFalse($license->allows());
    }

    public function testRequireGrantedThrowsWhenDenied(): void
    {
        [$client] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        $this->expectException(LicenseRequiredException::class);
        $license->requireGranted();
    }

    public function testForgetClearsAllowsSnapshot(): void
    {
        [$client] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());
        $license->request();
        self::assertTrue($license->allows());

        $license->forget();

        self::assertFalse($license->allows());
        self::assertNull($license->lastCheck());
    }

    public function testRequestIsIdempotentWhenAlreadyGranted(): void
    {
        [$client, , $server] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        $first = $license->request();
        $second = $license->request();

        self::assertSame($first->token, $second->token);
        self::assertCount(1, $server->tokenRequests, 'A still-valid grant must not mint again.');
    }

    public function testRequestReplacesAnInvalidStoredToken(): void
    {
        [$client, , $server] = $this->sealedClient();
        $store = new InMemoryTokenStore();
        $license = new License($client, $store, CreateTokenRequest::free());

        $first = $license->request();
        $client->deleteToken($first->token);

        $second = $license->request();

        self::assertNotSame($first->token, $second->token);
        self::assertSame($second->token, $license->token());
        self::assertCount(2, $server->tokenRequests);
        self::assertTrue($license->isGranted());
    }

    public function testForgetDropsTheStoredTokenWithoutRevoking(): void
    {
        [$client] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        $token = $license->request()->token;
        $license->forget();

        self::assertFalse($license->hasToken());
        self::assertTrue($client->verifyToken($token)->isValid(), 'forget() is local; the vKey stays live.');
    }

    public function testPrefixesExposeOnlyTheLookupHead(): void
    {
        [$client, $transport] = $this->sealedClient();
        $license = new License($client, new InMemoryTokenStore(), CreateTokenRequest::free());

        self::assertNull($license->tokenPrefix());
        self::assertNull($license->instancePrefix());

        $license->request();
        $before = count($transport->requests());

        $tokenPrefix = $license->tokenPrefix();
        $instancePrefix = $license->instancePrefix();

        self::assertSame(substr((string) $license->token(), 0, 8), $tokenPrefix);
        self::assertSame(substr((string) $client->storedInstanceId(), 0, 8), $instancePrefix);
        self::assertSame(8, strlen((string) $instancePrefix));
        self::assertSame($instancePrefix . '_' . $tokenPrefix, $license->tokenLocator());
        self::assertCount($before, $transport->requests(), 'Reading prefixes must not call the API.');
    }

    /**
     * @return array{0: ValidakeyClient, 1: MockHttpTransport, 2: FakeValidakeyServer}
     */
    private function sealedClient(): array
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/r/' => $server->rotateResponder(),
            'POST ' . self::BASE_URL . '/m/' => $server->tokenResponder(),
            'POST ' . self::BASE_URL . '/t/' => $server->transferResponder(),
            'POST ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
            'DELETE ' . self::BASE_URL . '/v/' => $server->tokenActionResponder(),
            'POST ' . self::BASE_URL . '/p/' => $server->paymentResponder(),
        ));

        $config = new ValidakeyConfig(
            baseUrl: self::BASE_URL,
            apiUUID: self::UUID,
            userAppId: self::USER_APP_ID,
            apiPKey: self::PKEY,
            host: self::HOST,
        );
        $client = new ValidakeyClient($config, $transport, new InMemoryInstanceStore());

        return array($client, $transport, $server);
    }
}
