<?php

declare(strict_types=1);

namespace Validakey\Tests;

use Validakey\Envelope;

/**
 * The server half of the handshake, in-process.
 *
 * This exercises the client against something that really does the prefix
 * lookup, candidate-key walk and sealed reply, rather than against a canned
 * ciphertext. It deliberately reuses Validakey\Envelope: agreement between
 * this and the actual vkey plugin is covered separately by the shared parity
 * fixture and the plugin's interop tool, so there is no value in a second
 * hand-written cipher here.
 */
final class FakeValidakeyServer
{
    /** @var array<string, list<string>> Registered app ids, keyed by their wire prefix. */
    private array $appIds = array();

    /** @var array<string, string> One instance per app and resolved subject. */
    private array $instancesByAppSubject = array();

    /** @var array<string, array{app_id: string, fingerprint: string, subject: string, resolved_subject: string}> */
    private array $instanceMeta = array();

    /** @var list<array{app_id: string, uuid: string, fingerprint: string, subject: string, rotated: bool}> */
    public array $handshakes = array();

    /** @var list<array{instance_id: string, ts: int, payload: array<string, mixed>}> */
    public array $tokenRequests = array();

    /** @var list<array{instance_id: string, payload: array<string, mixed>}> Requests to /v/. */
    public array $tokenActions = array();

    /** @var list<array{instance_id: string, payload: array<string, mixed>}> Requests to /p/. */
    public array $paymentActions = array();

    /** @var array<string, array{valid: bool, expires_at: int, uses: ?int, revoked: bool, app_id: string, subject: string}> vKeys by value. */
    public array $vkeys = array();

    /** @var array<string, array{has_card: bool, brand: string, last4: string}> IE cards by instance id. */
    private array $ieCards = array();

    /** @var array<string, true> Nonces already spent, for replay rejection. */
    private array $seenNonces = array();

    /** Forces the next token request to be answered as an unknown instance. */
    public bool $rejectNextTokenRequest = false;

    /**
     * When false, a handshake that authenticates is still refused for billing.
     *
     * The real server checks this only after the envelope has opened, so the
     * refusal is specific rather than the generic handshake rejection.
     */
    public bool $billingActive = true;

    /**
     * @param array<string, string> $accounts Map of app id => account uuid.
     */
    public function __construct(private readonly array $accounts)
    {
        foreach (array_keys($accounts) as $appId) {
            $this->appIds[Envelope::prefix($appId)][] = $appId;
        }
    }

    public static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Responder for POST /i/.
     */
    public function handshakeResponder(): callable
    {
        return function (string $method, string $url, array $options): MockResponse {
            $sealed = $options['json'][Envelope::WIRE_FIELD] ?? null;
            if (! is_string($sealed)) {
                return self::error(400, 'Missing sealed payload.', 'bad_request');
            }

            $parts = Envelope::split($sealed);

            // Prefix lookup, then walk every app id that shares the prefix.
            $candidates = $this->appIds[$parts['prefix']] ?? array();
            if (array() === $candidates) {
                return self::error(404, 'Unknown application prefix.', 'unknown_app');
            }

            $opened = Envelope::open($parts['cipher'], $candidates, function (string $plaintext, string $appId): bool {
                $fields = Envelope::unpackHandshake($plaintext);

                return null !== $fields && $this->accounts[$appId] === $fields['uuid'];
            });

            if (null === $opened) {
                return self::error(401, 'Handshake did not authenticate.', 'handshake_failed');
            }

            if (! $this->billingActive) {
                return self::error(402, 'Payment method required.', 'payment_required');
            }

            $fields = Envelope::unpackHandshake($opened['plaintext']);
            $fingerprint = $fields['fingerprint'];
            $subject = trim((string) $fields['subject']);
            if ('' === $subject) {
                return self::error(400, 'A subject is required.', 'missing_subject');
            }

            $instanceKey = self::instanceKey($opened['key_id'], $subject);
            $rotated = isset($this->instancesByAppSubject[$instanceKey]);

            // Rotate in place: one row per app and resolved subject.
            if ($rotated) {
                unset($this->instanceMeta[$this->instancesByAppSubject[$instanceKey]]);
            }

            $instanceId = self::uuid4();
            $this->instancesByAppSubject[$instanceKey] = $instanceId;
            $this->instanceMeta[$instanceId] = array(
                'app_id' => $opened['key_id'],
                'fingerprint' => $fingerprint,
                'subject' => $subject,
                'resolved_subject' => $subject,
            );

            $this->handshakes[] = array(
                'app_id' => $opened['key_id'],
                'uuid' => $fields['uuid'],
                'fingerprint' => $fingerprint,
                'subject' => $subject,
                'rotated' => $rotated,
            );

            return self::sealed(Envelope::packInstance($instanceId), $opened['key_id']);
        };
    }

    /**
     * Responder for POST /m/.
     */
    public function tokenResponder(): callable
    {
        return function (string $method, string $url, array $options): MockResponse {
            if ($this->rejectNextTokenRequest) {
                $this->rejectNextTokenRequest = false;

                return self::error(401, 'Unknown instance.', 'instance_unknown');
            }

            $sealed = $options['json'][Envelope::WIRE_FIELD] ?? null;
            if (! is_string($sealed)) {
                return self::error(400, 'Missing sealed payload.', 'bad_request');
            }

            $parts = Envelope::split($sealed);

            $candidates = array();
            foreach (array_keys($this->instanceMeta) as $instanceId) {
                if (Envelope::prefix($instanceId) === $parts['prefix']) {
                    $candidates[] = $instanceId;
                }
            }
            if (array() === $candidates) {
                return self::error(401, 'Unknown instance.', 'instance_unknown');
            }

            $opened = Envelope::open($parts['cipher'], $candidates, static function (string $plaintext): bool {
                return null !== Envelope::unpackRequest($plaintext);
            });

            if (null === $opened) {
                return self::error(401, 'Unknown instance.', 'instance_unknown');
            }

            $request = Envelope::unpackRequest($opened['plaintext']);

            if (isset($this->seenNonces[$request['nonce_hex']])) {
                return self::error(409, 'Replayed request.', 'replay');
            }
            $this->seenNonces[$request['nonce_hex']] = true;

            if (abs($request['ts'] - time()) > 300) {
                return self::error(400, 'Request timestamp out of range.', 'stale_request');
            }

            $this->tokenRequests[] = array(
                'instance_id' => $opened['key_id'],
                'ts' => $request['ts'],
                'payload' => $request['payload'],
            );

            $token = 'TOKEN-' . count($this->tokenRequests);
            $duration = (int) ($request['payload']['duration'] ?? 3600);

            $this->vkeys[$token] = array(
                'valid' => true,
                'expires_at' => empty($request['payload']['no_expiry']) ? $request['ts'] + $duration : 0,
                'uses' => isset($request['payload']['uses']) ? (int) $request['payload']['uses'] : null,
                'revoked' => false,
                'app_id' => $this->instanceMeta[$opened['key_id']]['app_id'],
                'subject' => $this->instanceMeta[$opened['key_id']]['resolved_subject'],
            );

            return self::sealed(
                Envelope::packReply(array(
                    'token' => $token,
                    'created' => $request['ts'],
                    'expires_at' => $this->vkeys[$token]['expires_at'],
                )),
                $opened['key_id']
            );
        };
    }

    /**
     * Responder for POST /r/.
     *
     * Replies sealed under the *old* instance id, as the real server does, so
     * a client that loses the response has not lost its credential.
     */
    public function rotateResponder(): callable
    {
        return $this->sealedRoute(function (string $instanceId, array $payload): MockResponse {
            $meta = $this->instanceMeta[$instanceId];
            $instanceKey = self::instanceKey($meta['app_id'], $meta['resolved_subject']);

            unset($this->instanceMeta[$instanceId]);

            $fresh = self::uuid4();
            $this->instancesByAppSubject[$instanceKey] = $fresh;
            $this->instanceMeta[$fresh] = $meta;

            // Carry the card over: rotating a credential is not a change of
            // customer, and losing their card on rotation would be a bug.
            if (isset($this->ieCards[$instanceId])) {
                $this->ieCards[$fresh] = $this->ieCards[$instanceId];
                unset($this->ieCards[$instanceId]);
            }

            return self::sealed(Envelope::packInstance($fresh), $instanceId);
        });
    }

    /**
     * Responder for POST|DELETE /v/.
     */
    public function tokenActionResponder(): callable
    {
        return $this->sealedRoute(function (string $instanceId, array $payload): MockResponse {
            $this->tokenActions[] = array(
                'instance_id' => $instanceId,
                'payload' => $payload,
            );

            $token = (string) ($payload['token'] ?? '');
            $action = (string) ($payload['action'] ?? 'validate');

            if (! isset($this->vkeys[$token])) {
                return $this->sealedReply($instanceId, array('valid' => false, 'reason' => 'not_found'));
            }

            $vkey = &$this->vkeys[$token];
            $meta = $this->instanceMeta[$instanceId];
            if ($vkey['app_id'] !== $meta['app_id'] || $vkey['subject'] !== $meta['resolved_subject']) {
                return $this->sealedReply($instanceId, array('valid' => false, 'reason' => 'not_found'));
            }

            if ('revoke' === $action) {
                $vkey['revoked'] = true;

                return $this->sealedReply($instanceId, array(
                    'valid' => false,
                    'reason' => 'revoked',
                    'revoked' => true,
                ));
            }

            if ('renew' === $action) {
                if (isset($payload['duration'])) {
                    $vkey['expires_at'] = time() + (int) $payload['duration'];
                }
                if (isset($payload['uses'])) {
                    $vkey['uses'] = (int) ($vkey['uses'] ?? 0) + (int) $payload['uses'];
                }
            }

            if ($vkey['revoked']) {
                $reason = 'revoked';
            } elseif ($vkey['expires_at'] > 0 && $vkey['expires_at'] <= time()) {
                $reason = 'expired';
            } elseif (null !== $vkey['uses'] && $vkey['uses'] <= 0) {
                $reason = 'depleted';
            } else {
                $reason = '';
            }

            if ('' !== $reason) {
                return $this->sealedReply($instanceId, array(
                    'valid' => false,
                    'reason' => $reason,
                    'expires_at' => $vkey['expires_at'],
                    'uses_remaining' => null === $vkey['uses'] ? null : max(0, $vkey['uses']),
                ));
            }

            if (! empty($payload['consume']) && null !== $vkey['uses']) {
                --$vkey['uses'];
            }

            return $this->sealedReply($instanceId, array(
                'valid' => true,
                'expires_at' => $vkey['expires_at'],
                'uses_remaining' => $vkey['uses'],
            ));
        });
    }

    /**
     * Responder for POST /p/.
     */
    public function paymentResponder(): callable
    {
        return $this->sealedRoute(function (string $instanceId, array $payload): MockResponse {
            $this->paymentActions[] = array(
                'instance_id' => $instanceId,
                'payload' => $payload,
            );

            $card = $this->ieCards[$instanceId] ?? array(
                'has_card' => false,
                'ie_status' => 'none',
                'brand' => '',
                'last4' => '',
            );

            switch ((string) ($payload['action'] ?? 'status')) {
                case 'attach':
                    if ('' === (string) ($payload['source_id'] ?? '')) {
                        return $this->sealedReply($instanceId, array(
                            'ok' => false,
                            'code' => 'missing_source_id',
                            'error' => 'A Square source_id is required.',
                        ));
                    }
                    $card = array('has_card' => true, 'ie_status' => 'active', 'brand' => 'VISA', 'last4' => '4242');
                    $this->ieCards[$instanceId] = $card;
                    break;

                case 'detach':
                    unset($this->ieCards[$instanceId]);
                    $card = array('has_card' => false, 'ie_status' => 'none', 'brand' => '', 'last4' => '');
                    break;

                case 'link':
                    return $this->sealedReply($instanceId, array(
                        'ok' => true,
                        'url' => 'https://example.validakey.host/v1/pay/?t=' . bin2hex(random_bytes(32)),
                        'expires_at' => time() + 900,
                        'single_use' => true,
                    ));
            }

            return $this->sealedReply($instanceId, array_merge(array('ok' => true), $card));
        });
    }

    public function instanceHasCard(string $instanceId): bool
    {
        return ! empty($this->ieCards[$instanceId]['has_card']);
    }

    /**
     * Responder for POST /t/.
     */
    public function transferResponder(): callable
    {
        return $this->sealedRoute(function (string $instanceId, array $payload): MockResponse {
            $subject = trim((string) ($payload['subject'] ?? ''));
            if ('' === $subject) {
                return self::error(400, 'A subject is required to transfer this instance.', 'missing_subject');
            }

            $meta = $this->instanceMeta[$instanceId];
            $targetKey = self::instanceKey($meta['app_id'], $subject);
            if (isset($this->instancesByAppSubject[$targetKey])) {
                return self::error(409, 'Another instance of this app already belongs to that subject.', 'subject_in_use');
            }

            $sourceKey = self::instanceKey($meta['app_id'], $meta['resolved_subject']);
            unset($this->instancesByAppSubject[$sourceKey], $this->instanceMeta[$instanceId], $this->ieCards[$instanceId]);

            $fresh = self::uuid4();
            $this->instancesByAppSubject[$targetKey] = $fresh;
            $this->instanceMeta[$fresh] = array(
                'app_id' => $meta['app_id'],
                'fingerprint' => $meta['fingerprint'],
                'subject' => $subject,
                'resolved_subject' => $subject,
            );

            foreach ($this->vkeys as &$vkey) {
                if ($vkey['app_id'] === $meta['app_id'] && $vkey['subject'] === $meta['resolved_subject']) {
                    $vkey['subject'] = $subject;
                }
            }
            unset($vkey);

            return self::sealed(Envelope::packInstance($fresh), $instanceId);
        });
    }

    /**
     * The lookup-decrypt-replay-check every instance-sealed route shares.
     *
     * One nonce store across all of them, matching the server: an envelope is
     * spent exactly once globally, so it cannot be replayed from one route
     * onto another.
     *
     * @param callable(string, array<string, mixed>): MockResponse $handler
     */
    private function sealedRoute(callable $handler): callable
    {
        return function (string $method, string $url, array $options) use ($handler): MockResponse {
            $sealed = $options['json'][Envelope::WIRE_FIELD] ?? null;
            if (! is_string($sealed)) {
                return self::error(400, 'Missing sealed payload.', 'missing_payload');
            }

            $parts = Envelope::split($sealed);

            $candidates = array();
            foreach (array_keys($this->instanceMeta) as $instanceId) {
                if (Envelope::prefix($instanceId) === $parts['prefix']) {
                    $candidates[] = $instanceId;
                }
            }
            if (array() === $candidates) {
                return self::error(401, 'Unknown instance.', 'instance_unknown');
            }

            $opened = Envelope::open($parts['cipher'], $candidates, static function (string $plaintext): bool {
                return null !== Envelope::unpackRequest($plaintext);
            });

            if (null === $opened) {
                return self::error(401, 'Unknown instance.', 'instance_unknown');
            }

            $request = Envelope::unpackRequest($opened['plaintext']);

            if (isset($this->seenNonces[$request['nonce_hex']])) {
                return self::error(409, 'Replayed request.', 'replay');
            }
            $this->seenNonces[$request['nonce_hex']] = true;

            return $handler($opened['key_id'], $request['payload']);
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sealedReply(string $instanceId, array $payload): MockResponse
    {
        return self::sealed(Envelope::packReply($payload), $instanceId);
    }

    public function instanceForSubject(string $appId, string $subject, ?string $fingerprint = null): ?string
    {
        unset($fingerprint);

        return $this->instancesByAppSubject[self::instanceKey($appId, $subject)] ?? null;
    }

    public function instanceCount(): int
    {
        return count($this->instancesByAppSubject);
    }

    private static function instanceKey(string $appId, string $subject): string
    {
        return $appId . '|' . $subject;
    }

    private static function sealed(string $plaintext, string $keyId): MockResponse
    {
        return new MockResponse(200, (string) json_encode(array(
            'out' => array(Envelope::WIRE_FIELD => Envelope::sealBare($plaintext, $keyId)),
        )));
    }

    private static function error(int $status, string $message, string $code): MockResponse
    {
        return new MockResponse($status, (string) json_encode(array(
            'ERROR' => $message,
            'CODE' => $code,
        )));
    }
}
