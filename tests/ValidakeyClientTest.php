<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\ApplicationContext;
use Validakey\Envelope;
use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;
use Validakey\Exception\ValidakeyException;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Request\AttachCardRequest;
use Validakey\Request\CreateTokenRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;

final class ValidakeyClientTest extends TestCase
{
    private const BASE_URL = 'https://example.validakey.host/v1';
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PKEY = '0123456789abcdef0123456789abcdef0123456789abcdef';
    private const USER_APP_ID = 'a7c93f10-2b4d-4e88-9f01-5c6d7e8f9a0b';
    private const HOST = 'test.example.com';

    // -----------------------------------------------------------------
    // Instance handshake
    // -----------------------------------------------------------------

    public function testHandshakeGrantsAnInstanceId(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $instance = $client->getInstanceToken();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $instance->instanceId
        );
        self::assertSame($client->machineFingerprint(), $instance->fingerprint);

        self::assertCount(1, $server->handshakes);
        self::assertSame(self::USER_APP_ID, $server->handshakes[0]['app_id']);
        self::assertSame(self::UUID, $server->handshakes[0]['uuid']);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $server->handshakes[0]['subject']
        );
        self::assertSame($server->handshakes[0]['subject'], $instance->subject);
        self::assertFalse($server->handshakes[0]['rotated']);
    }

    public function testOmittedSubjectIsAStableRandomId(): void
    {
        $store = new InMemoryInstanceStore();
        [$client, $transport, $server] = $this->sealedClient($store);

        $first = $client->getInstanceToken();
        $again = new ValidakeyClient($this->config(), $transport, $store);
        $second = $again->getInstanceToken();

        self::assertSame($first->subject, $second->subject);
        self::assertSame($first->subject, $again->subject());
        self::assertCount(2, $server->handshakes);
    }

    public function testOmittedSubjectDiffersAcrossStores(): void
    {
        [$alpha] = $this->sealedClient();
        [$beta] = $this->sealedClient();

        self::assertNotSame($alpha->subject(), $beta->subject());
    }

    public function testConfiguredSubjectIsSentUnchanged(): void
    {
        [$client, , $server] = $this->sealedClient(subject: 'customer-42');

        $instance = $client->getInstanceToken();

        self::assertSame('customer-42', $instance->subject);
        self::assertSame('customer-42', $server->handshakes[0]['subject']);
    }

    public function testHandshakeRejectsAnEmptySubject(): void
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
        ));
        $wire = Envelope::seal(
            Envelope::packHandshake(self::UUID, 'fp_' . self::HOST, ''),
            self::USER_APP_ID
        );

        $response = $transport->request('POST', self::BASE_URL . '/i/', array(
            'json' => array(Envelope::WIRE_FIELD => $wire),
        ));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('missing_subject', $response->toArray()['CODE'] ?? null);
        self::assertSame(0, $server->instanceCount());
    }

    public function testHandshakeSendsOnlyThePrefixInCleartext(): void
    {
        [$client, $transport] = $this->sealedClient();

        $client->getInstanceToken();

        $body = $transport->lastRequest()['options']['json'];
        self::assertSame(array(Envelope::WIRE_FIELD), array_keys($body), 'Handshake body must carry nothing but the sealed field.');

        $wire = $body[Envelope::WIRE_FIELD];
        self::assertSame(substr(self::USER_APP_ID, 0, 8), substr($wire, 0, 8));
        self::assertStringNotContainsString(self::USER_APP_ID, $wire);
        self::assertStringNotContainsString(self::UUID, $wire);
        self::assertStringNotContainsString($client->machineFingerprint(), $wire);
    }

    public function testHandshakeSendsNoIdentifyingHeaders(): void
    {
        [$client, $transport] = $this->sealedClient();

        $client->getInstanceToken();

        $headers = $transport->lastRequest()['options']['headers'];
        self::assertArrayNotHasKey(ApplicationContext::HEADER_USER_APP_ID, $headers);
        self::assertArrayNotHasKey(ApplicationContext::HEADER_USER_ID, $headers);
        self::assertArrayNotHasKey(ApplicationContext::HEADER_HOST, $headers);
        self::assertArrayNotHasKey('Authorization', $headers);
    }

    public function testInstanceIdIsReusedFromTheStore(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $first = $client->instanceId();
        $second = $client->instanceId();

        self::assertSame($first, $second);
        self::assertCount(1, $server->handshakes, 'A stored instance id must not trigger a second handshake.');
    }

    public function testRepeatHandshakeRotatesTheInstanceInPlace(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $first = $client->getInstanceToken()->instanceId;
        $second = $client->getInstanceToken()->instanceId;

        self::assertNotSame($first, $second, 'A repeat handshake must issue a fresh instance secret.');
        self::assertCount(2, $server->handshakes);
        self::assertTrue($server->handshakes[1]['rotated']);
        self::assertSame(1, $server->instanceCount(), 'Rotation must keep one instance per app and subject.');
        self::assertSame($second, $server->instanceForSubject(self::USER_APP_ID, $client->subject()));
    }

    public function testHandshakeFailsWhenTheServerCannotAuthenticate(): void
    {
        // A server that knows a different account for this app id cannot open the envelope.
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => '99999999-9999-4999-8999-999999999999'));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
        ));
        $client = $this->client($transport);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Handshake did not authenticate.');

        $client->getInstanceToken();
    }

    public function testUndecryptableReplyReportsAClockOrCredentialProblem(): void
    {
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => new MockResponse(200, (string) json_encode(array(
                'out' => array(Envelope::WIRE_FIELD => Envelope::sealBare('nonsense', 'ffffffff-ffff-4fff-8fff-ffffffffffff')),
            ))),
        ));

        $this->expectException(ValidakeyException::class);
        $this->expectExceptionMessage('Could not decrypt');

        $this->client($transport)->getInstanceToken();
    }

    // -----------------------------------------------------------------
    // Tokens over the sealed channel
    // -----------------------------------------------------------------

    public function testCreateTokenHandshakesThenRequestsOverTheSealedChannel(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 3600, basisCents: 999));

        self::assertSame('TOKEN-1', $token->token);
        self::assertSame(3600, $token->expiresAt - $token->created);

        self::assertCount(1, $server->handshakes);
        self::assertCount(1, $server->tokenRequests);
        self::assertSame(3600, $server->tokenRequests[0]['payload']['duration']);
        self::assertSame(999, $server->tokenRequests[0]['payload']['basis_cents']);
        self::assertSame(
            $server->instanceForSubject(self::USER_APP_ID, $client->subject()),
            $server->tokenRequests[0]['instance_id']
        );
    }

    public function testTokenRequestIsKeyedByTheInstanceNotTheApplication(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $client->createToken(new CreateTokenRequest());

        $wire = $transport->requestsTo('POST ' . self::BASE_URL . '/m/')[0]['options']['json'][Envelope::WIRE_FIELD];
        $instanceId = $server->instanceForSubject(self::USER_APP_ID, $client->subject());

        self::assertSame(substr((string) $instanceId, 0, 8), substr($wire, 0, 8));
        self::assertStringNotContainsString((string) $instanceId, $wire);
        self::assertNotSame(substr(self::USER_APP_ID, 0, 8), substr($wire, 0, 8));
    }

    public function testTokenRequestBodyCarriesNothingButTheSealedField(): void
    {
        [$client, $transport] = $this->sealedClient();

        $client->createToken(new CreateTokenRequest());

        $body = $transport->requestsTo('POST ' . self::BASE_URL . '/m/')[0]['options']['json'];

        self::assertSame(array(Envelope::WIRE_FIELD), array_keys($body));
    }

    public function testRepeatedIdenticalRequestsDifferOnTheWire(): void
    {
        [$client, $transport] = $this->sealedClient();

        $client->createToken(new CreateTokenRequest(duration: 3600));
        $client->createToken(new CreateTokenRequest(duration: 3600));

        $sent = $transport->requestsTo('POST ' . self::BASE_URL . '/m/');

        self::assertNotSame(
            $sent[0]['options']['json'][Envelope::WIRE_FIELD],
            $sent[1]['options']['json'][Envelope::WIRE_FIELD],
            'The per-request nonce must keep identical payloads from repeating on the wire.'
        );
    }

    public function testStaleInstanceTriggersOneReHandshakeAndSucceeds(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        // Prime the store, then make the server forget the instance.
        $client->instanceId();
        $server->rejectNextTokenRequest = true;

        $token = $client->createToken(new CreateTokenRequest());

        self::assertSame('TOKEN-1', $token->token);
        self::assertCount(2, $server->handshakes, 'A rejected instance must be re-handshaked exactly once.');
        self::assertCount(1, $server->tokenRequests);
    }

    public function testNonRecoverableTokenErrorIsNotRetried(): void
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/m/' => new MockResponse(402, (string) json_encode(array(
                'ERROR' => 'Payment method required.',
                'CODE' => 'payment_required',
            ))),
        ));
        $client = $this->client($transport);

        try {
            $client->createToken(new CreateTokenRequest());
            self::fail('Expected PaymentRequiredException');
        } catch (PaymentRequiredException $e) {
            self::assertSame('payment_required', $e->errorCode);
        }

        self::assertCount(1, $server->handshakes, 'A payment failure must not trigger a re-handshake.');
    }

    public function testHandshakeRefusedForBillingSurfacesAsPaymentRequired(): void
    {
        [$client, , $server] = $this->sealedClient();
        $server->billingActive = false;

        // The refusal is a 401-adjacent failure on the handshake, not on the
        // token request, so it has to escape createToken() rather than being
        // read as a stale instance and retried.
        try {
            $client->createToken(new CreateTokenRequest());
            self::fail('Expected PaymentRequiredException');
        } catch (PaymentRequiredException $e) {
            self::assertSame('payment_required', $e->errorCode);
            self::assertSame(402, $e->httpStatus);
        }

        self::assertSame(array(), $server->tokenRequests, 'No token may be requested without an instance.');
    }

    public function testRefusedHandshakeStoresNoInstance(): void
    {
        $store = new InMemoryInstanceStore();
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $server->billingActive = false;
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
        ));
        $client = new ValidakeyClient($this->config(self::USER_APP_ID), $transport, $store);

        try {
            $client->instanceId();
        } catch (PaymentRequiredException) {
            // expected
        }

        // A half-completed handshake must not leave anything behind to be
        // reused once billing is fixed.
        $server->billingActive = true;
        self::assertNotNull($client->instanceId());
        self::assertCount(1, $server->handshakes);
    }

    public function testInstanceStoreIsScopedByAppAndSubject(): void
    {
        $store = new InMemoryInstanceStore();
        $server = new FakeValidakeyServer(array(
            self::USER_APP_ID => self::UUID,
        ));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
        ));

        $first = new ValidakeyClient($this->config(self::USER_APP_ID, 'alpha'), $transport, $store);
        $second = new ValidakeyClient($this->config(self::USER_APP_ID, 'beta'), $transport, $store);

        $a = $first->instanceId();
        $b = $second->instanceId();

        self::assertNotSame($a, $b, 'Two subjects sharing a store must not share an instance.');
        self::assertCount(2, $server->handshakes);
    }

    public function testForSubjectSharesTransportAndStoreButUsesItsOwnInstanceBucket(): void
    {
        [$client, , $server] = $this->sealedClient();

        $alpha = $client->forSubject('alpha');
        $beta = $client->forSubject('beta');

        $a = $alpha->instanceId();
        $b = $beta->instanceId();

        self::assertNotSame($a, $b);
        self::assertSame($a, $server->instanceForSubject(self::USER_APP_ID, 'alpha'));
        self::assertSame($b, $server->instanceForSubject(self::USER_APP_ID, 'beta'));
    }

    public function testForgetInstanceForcesANewHandshake(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $first = $client->instanceId();
        $client->forgetInstance();
        $second = $client->instanceId();

        self::assertNotSame($first, $second);
        self::assertCount(2, $server->handshakes);
    }

    // -----------------------------------------------------------------
    // Validate, renew, revoke
    // -----------------------------------------------------------------

    public function testVerifyTokenOpensASealedResult(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 3600));
        $check = $client->verifyToken($token->token);

        self::assertTrue($check->isValid());
        self::assertSame('', $check->reason());
        self::assertSame($token->expiresAt, $check->expiresAt());
        self::assertNull($check->usesRemaining(), 'An unlimited vKey reports no count, not zero.');

        self::assertCount(1, $server->tokenActions);
        self::assertSame('validate', $server->tokenActions[0]['payload']['action']);
    }

    public function testVerifyTokenSendsNoIdentityAndNoCredential(): void
    {
        [$client, $transport] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest());
        $client->verifyToken($token->token);

        $sent = $transport->requestsTo('POST ' . self::BASE_URL . '/v/')[0];

        self::assertSame(array(Envelope::WIRE_FIELD), array_keys($sent['options']['json']));
        self::assertArrayNotHasKey('Authorization', $sent['options']['headers']);
        self::assertArrayNotHasKey('query', $sent['options']);

        // The point of sealing: the vKey itself is not readable in transit.
        self::assertStringNotContainsString(
            $token->token,
            $sent['options']['json'][Envelope::WIRE_FIELD]
        );
    }

    public function testValidationIsReadOnlyUnlessAskedToConsume(): void
    {
        [$client] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(uses: 2))->token;

        self::assertSame(2, $client->verifyToken($token)->usesRemaining());
        self::assertSame(2, $client->verifyToken($token)->usesRemaining());

        self::assertSame(1, $client->verifyToken($token, consume: true)->usesRemaining());
        self::assertSame(0, $client->verifyToken($token, consume: true)->usesRemaining());

        $depleted = $client->verifyToken($token);
        self::assertFalse($depleted->isValid());
        self::assertSame('depleted', $depleted->reason());
    }

    public function testUnknownTokenIsAnAnswerNotAnException(): void
    {
        [$client] = $this->sealedClient();

        $check = $client->verifyToken('NOSUCHTOKEN');

        self::assertFalse($check->isValid());
        self::assertSame('not_found', $check->reason());
    }

    public function testRenewAddsUsesWithoutChangingTheToken(): void
    {
        [$client, , $server] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(uses: 1))->token;
        $client->verifyToken($token, consume: true);

        $renewed = $client->renewToken($token, uses: 5);

        self::assertTrue($renewed->isValid());
        self::assertSame(5, $renewed->usesRemaining());
        self::assertSame('renew', $server->tokenActions[1]['payload']['action']);

        // Still the same vKey: a customer has already deployed it.
        self::assertTrue($client->verifyToken($token)->isValid());
    }

    public function testDeleteTokenRevokesOverTheDeleteVerb(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 3600))->token;
        $revoked = $client->deleteToken($token);

        self::assertTrue($revoked->wasRevoked());
        self::assertFalse($revoked->isValid());
        self::assertCount(1, $transport->requestsTo('DELETE ' . self::BASE_URL . '/v/'));
        self::assertSame('revoke', $server->tokenActions[0]['payload']['action']);

        $after = $client->verifyToken($token);
        self::assertFalse($after->isValid());
        self::assertSame('revoked', $after->reason());
    }

    public function testRevokingIsIdempotent(): void
    {
        [$client] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 3600))->token;

        self::assertTrue($client->deleteToken($token)->wasRevoked());
        self::assertTrue($client->deleteToken($token)->wasRevoked());
    }

    public function testANoExpiryTokenReportsNullRatherThanASentinel(): void
    {
        [$client] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 0, noExpiry: true))->token;

        $check = $client->verifyToken($token);

        self::assertTrue($check->isValid());
        self::assertNull($check->expiresAt());
    }

    // -----------------------------------------------------------------
    // Instance rotation
    // -----------------------------------------------------------------

    public function testRotateInstanceReplacesTheStoredId(): void
    {
        [$client, $transport, $server] = $this->sealedClient();

        $before = $client->instanceId();
        $after = $client->rotateInstance()->instanceId;

        self::assertNotSame($before, $after);
        self::assertSame($after, $client->instanceId(), 'The fresh id must be stored, not re-fetched.');
        self::assertCount(1, $server->handshakes, 'Rotation must not fall back to a handshake.');
        self::assertSame($after, $server->instanceForSubject(self::USER_APP_ID, $client->subject()));
    }

    public function testRotationIsSealedUnderTheOutgoingId(): void
    {
        [$client, $transport] = $this->sealedClient();

        $before = $client->instanceId();
        $client->rotateInstance();

        $wire = $transport->requestsTo('POST ' . self::BASE_URL . '/r/')[0]['options']['json'][Envelope::WIRE_FIELD];

        // Only a holder of the live id can rotate it, and that is the whole
        // authorization: no account credential is involved.
        self::assertSame(substr($before, 0, 8), substr($wire, 0, 8));
        self::assertStringNotContainsString($before, $wire);
    }

    public function testTokensStillWorkAfterRotation(): void
    {
        [$client] = $this->sealedClient();

        $token = $client->createToken(new CreateTokenRequest(duration: 3600))->token;
        $client->rotateInstance();

        self::assertTrue($client->verifyToken($token)->isValid());
        self::assertSame('TOKEN-2', $client->createToken(new CreateTokenRequest())->token);
    }

    public function testTransferSubjectMovesTheStoredInstanceToTheDestinationSubject(): void
    {
        [$client, , $server] = $this->sealedClient();

        $alpha = $client->forSubject('alpha');
        $before = $alpha->instanceId();

        $moved = $alpha->transferSubject('beta');
        $beta = $client->forSubject('beta');

        self::assertNotSame($before, $moved->instanceId);
        self::assertSame('beta', $moved->subject);
        self::assertSame($moved->instanceId, $beta->instanceId(), 'The destination subject should reuse the transferred id from the shared store.');
        self::assertNotSame($moved->instanceId, $alpha->instanceId(), 'The old subject should no longer hold the transferred instance.');
    }

    // -----------------------------------------------------------------
    // Instance Entity payment
    // -----------------------------------------------------------------

    public function testInstanceCardAttachDetachRoundTrip(): void
    {
        [$client, , $server] = $this->sealedClient();

        self::assertFalse($client->instancePaymentStatus()->hasCard());

        $attached = $client->attachInstanceCard(new AttachCardRequest(
            sourceId: 'cnon:card-nonce-ok',
            billingFields: array('postal_code' => '90210'),
        ));

        self::assertTrue($attached->isOk());
        self::assertTrue($attached->hasCard());
        self::assertSame('VISA', $attached->cardBrand());
        self::assertSame('4242', $attached->cardLast4());
        self::assertTrue($server->instanceHasCard($client->instanceId()));

        self::assertTrue($client->detachInstanceCard()->isOk());
        self::assertFalse($client->instancePaymentStatus()->hasCard());
    }

    public function testADeclineIsReportedNotThrown(): void
    {
        [$client] = $this->sealedClient();

        $result = $client->attachInstanceCard(new AttachCardRequest(sourceId: ''));

        self::assertFalse($result->isOk());
        self::assertSame('missing_source_id', $result->errorCode());
        self::assertNotSame('', $result->errorMessage());
    }

    public function testPaymentLinkIsReturnedForHeadlessClients(): void
    {
        [$client] = $this->sealedClient();

        $link = $client->requestInstancePaymentLink();

        self::assertTrue($link->isOk());
        self::assertStringStartsWith('https://', (string) $link->paymentUrl());
        self::assertGreaterThan(time(), (int) $link->expiresAt());
    }

    public function testMalformedPaymentReplyIsReportedNotThrown(): void
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/p/' => new MockResponse(200, '<html><h1>oops</h1></html>'),
        ));
        $client = $this->client($transport);

        $status = $client->instancePaymentStatus();

        self::assertFalse($status->isOk());
        self::assertSame('invalid_response', $status->errorCode());
        self::assertStringContainsString('malformed', $status->errorMessage());
    }

    public function testJsonPrefixedWithPhpNoticeStillOpens(): void
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/p/' => function (string $method, string $url, array $options) use ($server): MockResponse {
                $inner = $server->paymentResponder();
                $response = $inner($method, $url, $options);
                $json = $response->getContent(false);

                return new MockResponse(200, "Notice: Undefined index: foo in /var/www/vkey.php on line 12\n" . $json);
            },
        ));
        $client = $this->client($transport);

        $status = $client->instancePaymentStatus();

        self::assertTrue($status->isOk());
        self::assertFalse($status->hasCard());
    }

    public function testMalformedCreateTokenReplyThrowsApiException(): void
    {
        $server = new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID));
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => $server->handshakeResponder(),
            'POST ' . self::BASE_URL . '/m/' => new MockResponse(200, '<html>not json</html>'),
        ));
        $client = $this->client($transport);

        try {
            $client->createToken(new CreateTokenRequest());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('invalid_response', $e->errorCode);
            self::assertSame(200, $e->httpStatus);
            self::assertStringContainsString('HTTP 200', $e->getMessage());
            self::assertIsString($e->data);
            self::assertStringContainsString('<html>not json</html>', $e->data);
        }
    }

    public function testPaymentRouteCarriesNoAccountCredential(): void
    {
        [$client, $transport] = $this->sealedClient();

        $client->attachInstanceCard(new AttachCardRequest(sourceId: 'cnon:card-nonce-ok'));

        $sent = $transport->requestsTo('POST ' . self::BASE_URL . '/p/')[0];

        // The whole reason IE cards go through the sealed channel: the
        // customer holds an instance id and must never hold a private key.
        self::assertArrayNotHasKey('Authorization', $sent['options']['headers']);
        self::assertSame(array(Envelope::WIRE_FIELD), array_keys($sent['options']['json']));
    }

    public function testInstanceLockedIsSurfacedRatherThanRetried(): void
    {
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/i/' => (new FakeValidakeyServer(array(self::USER_APP_ID => self::UUID)))->handshakeResponder(),
            'POST ' . self::BASE_URL . '/m/' => new MockResponse(403, (string) json_encode(array(
                'ERROR' => 'This instance is locked to a different machine.',
                'CODE' => 'instance_locked',
            ))),
        ));

        $client = $this->client($transport);

        try {
            $client->createToken(new CreateTokenRequest());
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('instance_locked', $e->errorCode);
            self::assertSame(403, $e->httpStatus);
        }

        self::assertCount(1, $transport->requestsTo('POST ' . self::BASE_URL . '/i/'));
        self::assertCount(1, $transport->requestsTo('POST ' . self::BASE_URL . '/m/'));
    }

    // -----------------------------------------------------------------
    // Account endpoints (private key authenticated)
    // -----------------------------------------------------------------

    public function testGetBillingStatusRequiresThePrivateKey(): void
    {
        $client = new ValidakeyClient(
            new ValidakeyConfig(
                baseUrl: self::BASE_URL,
                apiUUID: self::UUID,
                userAppId: self::USER_APP_ID,
                host: self::HOST,
            ),
            new MockHttpTransport(array())
        );

        $this->expectException(ValidakeyException::class);
        $this->expectExceptionMessage('apiPKey is required');

        $client->getBillingStatus();
    }

    public function testGetBillingStatusSendsBearerAndCleartextContext(): void
    {
        $transport = new MockHttpTransport(array(
            'GET ' . self::BASE_URL . '/billing/status/' => new MockResponse(200, (string) json_encode(array(
                'status' => 'success',
                'data' => array(
                    'billing_status' => 'active',
                    'has_card' => true,
                    'last4' => '4242',
                ),
            ))),
        ));

        $status = $this->client($transport)->getBillingStatus();

        self::assertTrue($status->hasCard());
        self::assertSame('active', $status->billingStatus());

        $last = $transport->lastRequest();
        self::assertSame('Bearer ' . self::PKEY, $last['options']['headers']['Authorization']);
        self::assertSame(self::USER_APP_ID, $last['options']['query']['user_app_id']);
        self::assertSame(self::HOST, $last['options']['query']['host']);
        self::assertSame(self::UUID, $last['options']['query']['api_user_id']);
    }

    public function testGetUserInfoSuccess(): void
    {
        $transport = new MockHttpTransport(array(
            'GET ' . self::BASE_URL . '/u/' => new MockResponse(200, (string) json_encode(array(
                'status' => 'success',
                'data' => array(
                    'api_user_id' => self::UUID,
                    'billing' => array('billing_status' => 'active'),
                ),
            ))),
        ));

        $user = $this->client($transport)->getUserInfo();

        self::assertSame(self::UUID, $user->apiUserId);
        self::assertSame('active', $user->billing['billing_status']);
        self::assertSame(
            self::USER_APP_ID,
            $transport->lastRequest()['options']['headers'][ApplicationContext::HEADER_USER_APP_ID]
        );
    }

    public function testAttachAccountCardSuccess(): void
    {
        $transport = new MockHttpTransport(array(
            'POST ' . self::BASE_URL . '/billing/attach/' => new MockResponse(200, (string) json_encode(array(
                'status' => 'success',
                'data' => array('billing_status' => 'active'),
            ))),
        ));

        $result = $this->client($transport)->attachAccountCard(new AttachCardRequest(
            sourceId: 'cnon:test-nonce',
            billingFields: array('given_name' => 'Jane'),
        ));

        self::assertSame('active', $result['billing_status']);
    }

    public function testApiExceptionIncludesCode(): void
    {
        $transport = new MockHttpTransport(array(
            'GET ' . self::BASE_URL . '/u/' => new MockResponse(404, (string) json_encode(array(
                'ERROR' => 'Unknown API user.',
                'CODE' => 'unknown_user',
            ))),
        ));

        try {
            $this->client($transport)->getUserInfo();
            self::fail('Expected ApiException');
        } catch (ApiException $e) {
            self::assertSame('unknown_user', $e->errorCode);
            self::assertSame(404, $e->httpStatus);
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function config(string $userAppId = self::USER_APP_ID, ?string $subject = null): ValidakeyConfig
    {
        return new ValidakeyConfig(
            baseUrl: self::BASE_URL,
            apiUUID: self::UUID,
            userAppId: $userAppId,
            apiPKey: self::PKEY,
            subject: $subject,
            host: self::HOST,
        );
    }

    private function client(MockHttpTransport $transport): ValidakeyClient
    {
        return new ValidakeyClient($this->config(), $transport, new InMemoryInstanceStore());
    }

    /**
     * A client wired to a fake server that performs the real handshake.
     *
     * @return array{0: ValidakeyClient, 1: MockHttpTransport, 2: FakeValidakeyServer}
     */
    private function sealedClient(?InMemoryInstanceStore $store = null, ?string $subject = null): array
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

        $client = new ValidakeyClient(
            $this->config(subject: $subject),
            $transport,
            $store ?? new InMemoryInstanceStore()
        );

        return array($client, $transport, $server);
    }
}
