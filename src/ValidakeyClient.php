<?php

declare(strict_types=1);

namespace Validakey;

use Symfony\Component\HttpClient\HttpClient;
use Validakey\Exception\ApiException;
use Validakey\Exception\ValidakeyException;
use Validakey\Http\HttpTransport;
use Validakey\Http\SymfonyHttpTransport;
use Validakey\Instance\InMemoryInstanceStore;
use Validakey\Instance\InstanceStore;
use Validakey\Request\AttachCardRequest;
use Validakey\Request\CreateTokenRequest;
use Validakey\Response\BillingConfigResponse;
use Validakey\Response\BillingStatusResponse;
use Validakey\Response\InstancePaymentResponse;
use Validakey\Response\InstanceResponse;
use Validakey\Response\TokenResponse;
use Validakey\Response\TokenVerifyResponse;
use Validakey\Response\UserInfoResponse;

/**
 * Client for the Validakey API.
 *
 * Two authentication paths coexist, and which one a call uses decides whether
 * it is safe to ship.
 *
 * The token path (/i/, /m/, /v/, /r/, /p/) is authenticated by the instance
 * handshake. The client seals a payload with its user_app_id, the server
 * answers with an instance id, and that instance id keys every later request.
 * Nothing identifying travels in cleartext beyond an 8-character lookup
 * prefix, and no account credential is involved at any point. This is the path
 * software distributed to customers uses.
 *
 * The account path (/u/ and /billing/*) is authenticated by the account
 * private key, and only those calls carry the cleartext ApplicationContext
 * headers. The private key must never appear in software you distribute or in
 * a public repository: it can change billing details and read usage for the
 * whole account. Minting and validating licenses never needs it.
 */
final class ValidakeyClient
{
    /**
     * HTTP statuses that mean the server no longer recognises our instance id,
     * which happens whenever another process rotates it.
     */
    private const STALE_INSTANCE_STATUSES = array(401, 403, 409);

    private readonly HttpTransport $transport;
    private readonly ApplicationContext $context;
    private readonly ValidakeyConfig $config;
    private readonly InstanceStore $instanceStore;

    private ?string $fingerprint = null;

    public function __construct(
        ValidakeyConfig $config,
        ?HttpTransport $transport = null,
        ?InstanceStore $instanceStore = null,
    ) {
        $this->config = $config;
        if (null === $transport) {
            $transport = new SymfonyHttpTransport(
                HttpClient::create(array(
                    'timeout' => $this->config->timeout,
                ))
            );
        }
        $this->transport = $transport;
        $this->context = $this->config->applicationContext();
        $this->instanceStore = $instanceStore ?? new InMemoryInstanceStore();
    }

    // -----------------------------------------------------------------
    // Machine identity
    // -----------------------------------------------------------------

    /**
     * A stable identifier for this machine.
     *
     * product_uuid survives an OS reinstall but is usually root-only;
     * machine-id is world-readable but is regenerated on reinstall. Trying
     * them in that order prefers stability and degrades to availability.
     */
    public static function machineId(): string
    {
        $paths = array('/sys/class/dmi/id/product_uuid', '/etc/machine-id', '/var/lib/dbus/machine-id');
        foreach ($paths as $path) {
            if (is_readable($path)) {
                $value = @file_get_contents($path);
                if (false !== $value && '' !== trim($value)) {
                    return trim($value);
                }
            }
        }

        return str_repeat('9', 32);
    }

    /**
     * Machine id combined with the host this application answers on.
     *
     * The host is part of the identity because several applications on one
     * machine may serve different hosts, and each should get its own instance.
     */
    public static function fingerprint(?string $host = null): string
    {
        return self::machineId() . '_' . ApplicationContext::resolveHost($host);
    }

    /**
     * The obfuscated timestamp used in the key schedule.
     *
     * Delegates to Envelope, which is the single definition shared with the
     * server. Kept here because callers reach for it on the client.
     */
    public static function modTime(string $keyId, int $bits = Envelope::TIME_BITS): int
    {
        return Envelope::modTime($keyId, 0, $bits);
    }

    public function applicationContext(): ApplicationContext
    {
        return $this->context;
    }

    // -----------------------------------------------------------------
    // Instance handshake
    // -----------------------------------------------------------------

    /**
     * Perform the handshake and return a fresh instance id.
     *
     * M1 seals nonce + account uuid + subject + fingerprint under the
     * user_app_id and puts only the app id prefix in cleartext. The server
     * resolves the prefix to candidate app ids, finds the one that decrypts,
     * and replies with M2: the instance id sealed under the same key.
     *
     * Calling this always rotates the instance server-side. Use instanceId()
     * to reuse the stored one.
     */
    public function getInstanceToken(): InstanceResponse
    {
        $appId = $this->config->userAppId;
        $fingerprint = $this->machineFingerprint();
        $subject = $this->subject();

        $sealed = Envelope::seal(
            Envelope::packHandshake($this->config->apiUUID, $fingerprint, $subject),
            $appId
        );

        $payload = $this->requestJson(
            'POST',
            $this->endpointUrl('i'),
            array(Envelope::WIRE_FIELD => $sealed),
            false
        );

        $instanceId = Envelope::unpackInstance(
            $this->openReply($payload, $appId, 'instance handshake')
        );

        if (null === $instanceId) {
            throw new ValidakeyException('Instance handshake reply did not contain a valid instance id.');
        }

        $this->instanceStore->set($this->instanceStoreKey(), $instanceId);

        return new InstanceResponse(
            instanceId: $instanceId,
            fingerprint: $fingerprint,
            subject: $subject,
        );
    }

    /**
     * The stored instance id, performing a handshake only if we do not have one.
     */
    public function instanceId(): string
    {
        $stored = $this->storedInstanceId();
        if (null !== $stored) {
            return $stored;
        }

        return $this->getInstanceToken()->instanceId;
    }

    /**
     * The instance id already in the store, or null.
     *
     * Unlike {@see instanceId()}, this never handshakes — safe for admin
     * display of an identifying prefix without rotating credentials.
     */
    public function storedInstanceId(): ?string
    {
        $stored = $this->instanceStore->get($this->instanceStoreKey());

        return is_string($stored) && '' !== $stored ? $stored : null;
    }

    /**
     * Replace the current instance id with a fresh one.
     *
     * Sealed under the id being replaced, which is what authorizes the call:
     * only a holder of the live instance id can rotate it. The reply is sealed
     * under the *old* id too, so a client that loses the response has not lost
     * its credential — it still holds the key needed to open a retry.
     *
     * Prefer this over getInstanceToken() for routine rotation. The handshake
     * flags a rotation that closely follows activity as a possible cloned app
     * id; an explicit rotation is not flagged, because the caller asked for it
     * and had to prove it held the live id to ask.
     */
    public function rotateInstance(): InstanceResponse
    {
        $current = $this->instanceId();

        $sealed = Envelope::seal(Envelope::packRequest(array()), $current);

        $payload = $this->requestJson(
            'POST',
            $this->endpointUrl('r'),
            array(Envelope::WIRE_FIELD => $sealed),
            false
        );

        $instanceId = Envelope::unpackInstance(
            $this->openReply($payload, $current, 'instance rotation')
        );

        if (null === $instanceId) {
            throw new ValidakeyException('Instance rotation reply did not contain a valid instance id.');
        }

        $this->instanceStore->set($this->instanceStoreKey(), $instanceId);

        return new InstanceResponse(
            instanceId: $instanceId,
            fingerprint: $this->machineFingerprint(),
            subject: $this->subject(),
        );
    }

    /**
     * A subject-scoped view of this client, sharing transport and instance store.
     *
     * The shared store is deliberate: a transfer from one subject to another
     * lands the fresh instance id into the destination subject's bucket, and
     * the forked client should be able to reuse it immediately.
     */
    public function forSubject(?string $subject): self
    {
        return new self(
            $this->config->withSubject($subject),
            $this->transport,
            $this->instanceStore
        );
    }

    /**
     * Discard the stored instance id, forcing a handshake on the next call.
     */
    public function forgetInstance(): void
    {
        $this->instanceStore->forget($this->instanceStoreKey());
    }

    /**
     * This client's fingerprint, memoised because the reads touch the filesystem.
     */
    public function machineFingerprint(): string
    {
        return $this->fingerprint ??= self::fingerprint($this->config->host);
    }

    // -----------------------------------------------------------------
    // Tokens
    // -----------------------------------------------------------------

    /**
     * Request a token over the sealed channel.
     *
     * If the server has rotated our instance out from under us, the stored id
     * is dropped and the handshake is repeated once before giving up.
     */
    public function createToken(CreateTokenRequest $request): TokenResponse
    {
        // Resolved outside the try so a failing handshake propagates as-is
        // instead of being mistaken for a stale instance and retried.
        $instanceId = $this->instanceId();

        try {
            return $this->createTokenWithInstance($request, $instanceId);
        } catch (ApiException $e) {
            if (! $this->shouldRetryStaleInstance($e)) {
                throw $e;
            }
        }

        $this->forgetInstance();

        return $this->createTokenWithInstance($request, $this->getInstanceToken()->instanceId);
    }

    private function createTokenWithInstance(CreateTokenRequest $request, string $instanceId): TokenResponse
    {
        $sealed = Envelope::seal(Envelope::packRequest($request->toArray()), $instanceId);

        $payload = $this->requestJson(
            'POST',
            $this->endpointUrl('m'),
            array(Envelope::WIRE_FIELD => $sealed),
            false
        );

        $reply = Envelope::unpackReply(
            $this->openReply($payload, $instanceId, 'token request')
        );

        if (null === $reply) {
            throw new ValidakeyException('Token reply did not contain a decodable payload.');
        }

        return TokenResponse::fromArray($reply);
    }

    /**
     * Check whether a vKey is still good.
     *
     * Read-only by default. Pass $consume to spend one use of a use-limited
     * vKey, which is a different question: "is this key valid" is asked on
     * every startup, while "the holder just used it" happens once per use, and
     * conflating them would drain a type 3 allowance on health checks alone.
     *
     * Never gated on the account's billing state. A customer who paid for a
     * vKey keeps it even if the account that sold it lapses.
     */
    public function verifyToken(string $token, bool $consume = false): TokenVerifyResponse
    {
        return TokenVerifyResponse::fromArray($this->tokenAction(
            'POST',
            array(
                'action' => 'validate',
                'token' => $token,
                'consume' => $consume,
            )
        ));
    }

    /**
     * Extend a vKey with more time, more uses, or both.
     *
     * The vKey value does not change. A customer has already deployed it, so
     * issuing a replacement would break every copy in the field. Where the
     * vKey carries a price, the renewal is charged to the Instance Entity's
     * card, and a decline comes back as an invalid result with the decline
     * code rather than as an exception.
     */
    public function renewToken(string $token, ?int $duration = null, ?int $uses = null): TokenVerifyResponse
    {
        $payload = array(
            'action' => 'renew',
            'token' => $token,
        );

        if (null !== $duration) {
            $payload['duration'] = $duration;
        }
        if (null !== $uses) {
            $payload['uses'] = $uses;
        }

        return TokenVerifyResponse::fromArray($this->tokenAction('POST', $payload));
    }

    /**
     * Revoke a vKey.
     *
     * Idempotent, and the record survives: usage and billing history reference
     * the token, so it is marked rather than removed. Revoking is the only
     * thing that can end a type 1 vKey, which otherwise never expires.
     */
    public function deleteToken(string $token): TokenVerifyResponse
    {
        return TokenVerifyResponse::fromArray($this->tokenAction(
            'DELETE',
            array(
                'action' => 'revoke',
                'token' => $token,
            )
        ));
    }

    // -----------------------------------------------------------------
    // Instance Entity payment (sealed, no account key)
    // -----------------------------------------------------------------

    /**
     * Whether this instance has a card on file, with brand and last four.
     */
    public function instancePaymentStatus(): InstancePaymentResponse
    {
        return $this->instancePaymentAction(array('action' => 'status'));
    }

    /**
     * Store a card for this Instance Entity from a Square `source_id`.
     *
     * The card number never passes through this library or the Validakey
     * server. The host application's frontend collects it with Square's Web
     * Payments SDK, which returns a `source_id`; only that token is sealed and
     * sent here. For clients with no browser to host that form in, use
     * requestInstancePaymentLink() instead.
     */
    public function attachInstanceCard(AttachCardRequest $request): InstancePaymentResponse
    {
        return $this->instancePaymentAction(array(
            'action' => 'attach',
            'source_id' => $request->sourceId,
            'billing' => $request->billingFields,
        ));
    }

    /**
     * Forget this Instance Entity's card.
     *
     * The Square customer is kept, so re-entering a card later lands on the
     * same customer rather than scattering duplicates.
     */
    public function detachInstanceCard(): InstancePaymentResponse
    {
        return $this->instancePaymentAction(array('action' => 'detach'));
    }

    /**
     * Mint a one-time URL where the Instance Entity can enter a card.
     *
     * For CLI, desktop and other headless clients, which the fingerprint
     * identity model implies are common: print the URL, the customer opens it
     * in any browser, and the card goes from there straight to Square. The
     * link is single-use, expires in fifteen minutes, and can do nothing but
     * attach a card to this one instance.
     */
    public function requestInstancePaymentLink(): InstancePaymentResponse
    {
        return $this->instancePaymentAction(array('action' => 'link'));
    }

    /**
     * Hand this Instance Entity to a different end customer.
     *
     * The request is sealed under the current instance id and returns the new
     * instance id sealed under the old one. The old subject's stored key is
     * removed and the new one is stored under the destination subject, so a
     * sibling client from forSubject() can reuse it without re-handshaking.
     */
    public function transferSubject(string $subject): InstanceResponse
    {
        $current = $this->instanceId();
        $subject = trim($subject);
        if ('' === $subject) {
            throw new \InvalidArgumentException('subject must not be empty.');
        }

        $reply = $this->sealedRoundTrip('POST', 't', array('subject' => $subject), $current);
        $instanceId = isset($reply['instance_id']) ? (string) $reply['instance_id'] : null;

        if (null === $instanceId || '' === $instanceId) {
            throw new ValidakeyException('Instance transfer reply did not contain a valid instance id.');
        }

        $this->instanceStore->forget($this->instanceStoreKey());
        $this->instanceStore->set($this->instanceStoreKeyForSubject($subject), $instanceId);

        return new InstanceResponse(
            instanceId: $instanceId,
            fingerprint: $this->machineFingerprint(),
            subject: $subject,
        );
    }

    // -----------------------------------------------------------------
    // Account endpoints (private key authenticated)
    //
    // These act on the account holder — the developer who owns the Validakey
    // account — and are the only calls that read the private key. Do not
    // reach for them from software you ship to customers; see the class
    // docblock.
    // -----------------------------------------------------------------

    public function getUserInfo(): UserInfoResponse
    {
        $payload = $this->requestJson(
            'GET',
            $this->endpointUrl('u'),
            null,
            true
        );

        return UserInfoResponse::fromSuccessEnvelope($payload);
    }

    public function getBillingConfig(): BillingConfigResponse
    {
        $payload = $this->requestJson(
            'GET',
            $this->billingUrl('config'),
            null,
            true
        );

        return BillingConfigResponse::fromSuccessEnvelope($payload);
    }

    public function getBillingStatus(): BillingStatusResponse
    {
        $payload = $this->requestJson(
            'GET',
            $this->billingUrl('status'),
            null,
            true
        );

        return BillingStatusResponse::fromSuccessEnvelope($payload);
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureCustomer(): array
    {
        $payload = $this->requestJson(
            'POST',
            $this->billingUrl('ensure_customer'),
            array(),
            true
        );

        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    /**
     * Store a card for the *account holder*, which their monthly dues are
     * charged to. This is not the customer's card; see attachInstanceCard().
     *
     * @return array<string, mixed>
     */
    public function attachAccountCard(AttachCardRequest $request): array
    {
        $payload = $this->requestJson(
            'POST',
            $this->billingUrl('attach'),
            $request->toArray(),
            true
        );

        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function detachAccountCard(): array
    {
        $payload = $this->requestJson(
            'POST',
            $this->billingUrl('detach'),
            array(),
            true
        );

        return isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : $payload;
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    /**
     * Extract and decrypt the sealed field from a reply body.
     *
     * @param array<string, mixed> $payload
     */
    private function openReply(array $payload, string $keyId, string $what): string
    {
        $out = isset($payload['out']) && is_array($payload['out']) ? $payload['out'] : $payload;
        $sealed = $out[Envelope::WIRE_FIELD] ?? null;

        if (! is_string($sealed) || '' === $sealed) {
            throw new ValidakeyException(sprintf('Validakey %s reply did not include a sealed payload.', $what));
        }

        $plaintext = Envelope::openWith($sealed, $keyId);
        if (null === $plaintext) {
            throw new ValidakeyException(sprintf(
                'Could not decrypt the Validakey %s reply. This usually means the client and server clocks '
                . 'differ by more than the key rotation window, or the credentials do not match.',
                $what
            ));
        }

        return $plaintext;
    }

    /**
     * End-customer subject this client is scoped to.
     *
     * An explicit {@see ValidakeyConfig::$subject} wins. Otherwise a random
     * Subject ID is generated once and stored alongside the instance id, so a
     * single-tenant install is a real Instance Entity rather than a fingerprint
     * stand-in.
     */
    public function subject(): string
    {
        if ($this->hasExplicitSubject()) {
            return trim((string) $this->config->subject);
        }

        return $this->ensureAutoSubject();
    }

    /**
     * Whether the caller named a subject (including WordPress site-host defaults).
     */
    public function hasExplicitSubject(): bool
    {
        return null !== $this->config->subject && '' !== trim($this->config->subject);
    }

    /**
     * Store key for this app, subject, and machine fingerprint.
     *
     * Shared by the instance id store and the vKey store so a subject's
     * handshake and license land in matching buckets.
     */
    public function storeKey(): string
    {
        return $this->instanceStoreKey();
    }

    /**
     * Key under which this application's instance id is stored.
     *
     * Scoped by app id and fingerprint so several applications on one machine,
     * or one application across several hosts, never share an instance.
     */
    private function instanceStoreKey(): string
    {
        return $this->instanceStoreKeyForSubject($this->subject());
    }

    private function instanceStoreKeyForSubject(string $subject): string
    {
        return hash('sha256', $this->config->userAppId . '|' . $subject . '|' . $this->machineFingerprint());
    }

    private function autoSubjectStoreKey(): string
    {
        return hash('sha256', $this->config->userAppId . '|__auto_subject');
    }

    private function ensureAutoSubject(): string
    {
        $key = $this->autoSubjectStoreKey();
        $existing = $this->instanceStore->get($key);
        if (is_string($existing) && '' !== trim($existing)) {
            return trim($existing);
        }

        $generated = self::generateSubjectId();
        $this->instanceStore->set($key, $generated);

        return $generated;
    }

    /**
     * A UUIDv4 suitable as a single-tenant Subject ID.
     */
    public static function generateSubjectId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    private function endpointUrl(string $action): string
    {
        return sprintf(
            '%s/%s/',
            $this->config->normalizedBaseUrl(),
            rawurlencode($action)
        );
    }

    private function billingUrl(string $action): string
    {
        return sprintf(
            '%s/billing/%s/',
            $this->config->normalizedBaseUrl(),
            rawurlencode($action)
        );
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function requestJson(
        string $method,
        string $url,
        ?array $body,
        bool $requireAuth,
    ): array {
        $options = $this->buildRequestOptions($method, $body, $requireAuth);
        $response = $this->transport->request($method, $url, $options);
        $status = $response->getStatusCode();
        $content = $response->getContent(false);
        $payload = $this->decodeJson($content);

        if ($status >= 400) {
            $this->throwIfErrorPayload($payload, $status, $content);
        }

        if (! is_array($payload)) {
            throw new ApiException(
                'Validakey API returned a malformed (non-JSON) response (HTTP ' . $status . ').',
                'invalid_response',
                $status,
                $this->responseSnippet($content)
            );
        }

        return $payload;
    }

    /**
     * Build transport options.
     *
     * The cleartext ApplicationContext is attached only to Bearer-authenticated
     * calls. Sealed requests carry their identity inside the envelope, so
     * adding these fields there would defeat the point of encrypting them.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function buildRequestOptions(string $method, ?array $body, bool $requireAuth): array
    {
        $options = array(
            'headers' => $this->defaultHeaders($requireAuth),
        );

        if ('GET' === strtoupper($method)) {
            $query = $requireAuth ? $this->context->queryParams() : array();
            if (null !== $body) {
                $query = array_merge($query, $body);
            }
            if (! empty($query)) {
                $options['query'] = $query;
            }

            return $options;
        }

        $base = $requireAuth ? $this->context->bodyFields() : array();
        $options['json'] = array_merge($base, $body ?? array());

        return $options;
    }

    /**
     * Card operations report failure in the result object, including a
     * malformed HTTP body. Handshake/billing exceptions still propagate so a
     * missing seller card is not mistaken for "this instance has no card".
     *
     * @param array<string, mixed> $payload
     */
    private function instancePaymentAction(array $payload): InstancePaymentResponse
    {
        try {
            return InstancePaymentResponse::fromArray(
                $this->tokenAction('POST', $payload, 'p')
            );
        } catch (PaymentRequiredException $e) {
            throw $e;
        } catch (ValidakeyException $e) {
            return InstancePaymentResponse::fromException($e);
        }
    }

    /**
     * Seal a payload under the instance id, send it, and open the reply.
     *
     * Shared by every instance-sealed route past the handshake. Retries once
     * through a fresh handshake if the server no longer knows our instance id,
     * matching createToken(). Another process on the same machine rotating the
     * instance is ordinary, not an error the caller should see.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed> Opened reply payload.
     */
    private function tokenAction(string $method, array $payload, string $action = 'v'): array
    {
        // Resolved outside the try so a failing handshake propagates as-is
        // instead of being mistaken for a stale instance and retried.
        $instanceId = $this->instanceId();

        try {
            return $this->sealedRoundTrip($method, $action, $payload, $instanceId);
        } catch (ApiException $e) {
            if (! $this->shouldRetryStaleInstance($e)) {
                throw $e;
            }
        }

        $this->forgetInstance();

        return $this->sealedRoundTrip($method, $action, $payload, $this->getInstanceToken()->instanceId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sealedRoundTrip(string $method, string $action, array $payload, string $instanceId): array
    {
        $sealed = Envelope::seal(Envelope::packRequest($payload), $instanceId);

        $body = $this->requestJson(
            $method,
            $this->endpointUrl($action),
            array(Envelope::WIRE_FIELD => $sealed),
            false
        );

        $plaintext = $this->openReply($body, $instanceId, 'sealed ' . $action . ' reply');

        if ('t' === $action) {
            $fresh = Envelope::unpackInstance($plaintext);
            if (null === $fresh) {
                throw new ValidakeyException('Validakey /t/ reply did not contain a valid instance id.');
            }

            return array('instance_id' => $fresh);
        }

        $reply = Envelope::unpackReply($plaintext);

        if (null === $reply) {
            throw new ValidakeyException(sprintf(
                'Validakey /%s/ reply did not contain a decodable payload.',
                $action
            ));
        }

        return $reply;
    }

    /**
     * @return array<string, string>
     */
    private function defaultHeaders(bool $requireAuth): array
    {
        $headers = array(
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        );

        if (! $requireAuth) {
            return $headers;
        }

        $headers = array_merge($headers, $this->context->headers());

        if (! $this->config->hasPKey()) {
            throw new ValidakeyException('apiPKey is required for this Validakey API call.');
        }

        $headers['Authorization'] = 'Bearer ' . $this->config->apiPKey;

        return $headers;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $candidates = array($this->stripBom(trim($content)));
        $salvaged = $this->jsonPayloadFromMixed($content);
        if (null !== $salvaged && $salvaged !== $candidates[0]) {
            $candidates[] = $salvaged;
        }

        foreach ($candidates as $candidate) {
            if ('' === $candidate) {
                continue;
            }

            $decoded = json_decode($candidate, true);
            if (JSON_ERROR_NONE === json_last_error() && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Recover a JSON object/array when the server prepends PHP notices or BOM.
     */
    private function jsonPayloadFromMixed(string $content): ?string
    {
        $content = $this->stripBom($content);
        $curly = strpos($content, '{');
        $square = strpos($content, '[');
        $start = false;
        if (false !== $curly && (false === $square || $curly < $square)) {
            $start = $curly;
        } elseif (false !== $square) {
            $start = $square;
        }

        if (false === $start || 0 === $start) {
            return null;
        }

        return trim(substr($content, $start));
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }

    private function responseSnippet(string $content): string
    {
        $snippet = preg_replace('/\s+/', ' ', trim($content)) ?? '';
        if (strlen($snippet) > 280) {
            $snippet = substr($snippet, 0, 277) . '...';
        }

        return $snippet;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function throwIfErrorPayload(?array $payload, int $status, string $raw = ''): void
    {
        if (null !== $payload && isset($payload['ERROR'])) {
            throw ApiException::fromErrorPayload($payload, $status);
        }

        throw new ApiException(
            'Validakey API request failed with HTTP ' . $status . '.',
            null === $payload ? 'invalid_response' : null,
            $status,
            null === $payload ? $this->responseSnippet($raw) : ($payload['DATA'] ?? null)
        );
    }

    private function shouldRetryStaleInstance(ApiException $e): bool
    {
        if (! in_array($e->httpStatus, self::STALE_INSTANCE_STATUSES, true)) {
            return false;
        }

        return 'instance_locked' !== $e->errorCode;
    }
}
