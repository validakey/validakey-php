# Error handling

The Validakey PHP client maps HTTP failures and server error payloads to typed exceptions.

## Exception hierarchy

```
RuntimeException
└── ValidakeyException
    └── ApiException
        └── PaymentRequiredException
```

| Exception | When thrown |
|-----------|-------------|
| `ValidakeyException` | Client misconfiguration (missing secret), or a sealed reply that would not decrypt |
| `ApiException` | Server returned `{ "ERROR": "…" }`, HTTP ≥ 400 without a known payload, or a malformed (non-JSON) 2xx body (`invalid_response`) |
| `PaymentRequiredException` | HTTP 402 or `CODE: payment_required` (billing card required before token create) |

All `ApiException` instances expose:

| Property | Type | Description |
|----------|------|-------------|
| `getMessage()` | `string` | Value of server `ERROR` field |
| `errorCode` | `?string` | Value of server `CODE` field |
| `httpStatus` | `int` | HTTP status code |
| `data` | `mixed` | Optional server `DATA` field |

## Example

```php
use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;
use Validakey\Exception\ValidakeyException;
use Validakey\Request\CreateTokenRequest;

try {
    $client->createToken(new CreateTokenRequest());
} catch (PaymentRequiredException $e) {
    if ('ie_card_required' === $e->errorCode) {
        // The customer needs to enter a card, not you. Send them to
        // requestInstancePaymentLink() or attachInstanceCard().
    }
    error_log('Validakey billing required: ' . $e->getMessage());
} catch (ApiException $e) {
    error_log(sprintf(
        'Validakey API error [%s] HTTP %d: %s',
        $e->errorCode ?? 'unknown',
        $e->httpStatus,
        $e->getMessage()
    ));
} catch (ValidakeyException $e) {
    error_log('Validakey client error: ' . $e->getMessage());
}
```

## Common error codes

| HTTP | CODE | Meaning | Suggested action |
|------|------|---------|-------------------|
| 402 | `payment_required` | *Your* account has no card on file. Refuses both the handshake and the token request. | Attach a card on the Validakey dashboard, or via `attachAccountCard()` |
| 402 | `dues_past_due` | Your monthly service fee is unpaid past its grace period. Blocks minting; validation keeps working. | Update your card |
| 402 | `ie_card_required` | The **customer** has no card on file and the vKey carries a price. Checked before anything is minted. | `attachInstanceCard()` or `requestInstancePaymentLink()` |
| 401 | `unauthorized` | Invalid or missing Bearer token | Check `apiPKey` |
| 401 | `handshake_failed` | The handshake did not authenticate | See below |
| 401 | `instance_unknown` | The sealed token request did not authenticate | Usually a rotated instance; the client retries once by itself |
| 400 | `missing_payload` | Request body had no sealed field | Client bug or a proxy stripping the body |
| 400 | `stale_request` | Client timestamp outside the accepted window | Fix the client clock |
| 404 | `unknown_user` | Account UUID not found | Verify `apiUUID` |
| 404 | — | Invalid billing route | Check base URL and UUID |
| 405 | — | Wrong HTTP method | Client bug or API change |
| 200 | `invalid_response` | Body was empty, HTML, or otherwise not JSON | Check the API host and server error log; PHP notices in front of JSON are recovered automatically |
| 500 | — | Server error | Retry or contact Validakey admin |
| 503 | — | Billing or database unavailable | Retry later |

## Handshake failures

`handshake_failed` is deliberately the *only* answer the handshake endpoint gives to a bad request. An unknown prefix, a wrong app id, a bad account UUID, and a replayed nonce are indistinguishable, because a more specific reply would let an attacker enumerate valid app id prefixes.

Billing is the one exception: a caller that authenticates but has no card on file gets `payment_required` (402), not `handshake_failed`. Naming it costs nothing, because reaching that point already required the app id, and a generic 401 would send you debugging credentials that are in fact fine.

Otherwise the uniformity makes it unhelpful for debugging by design. Work through the causes in order:

1. **`userAppId` or `apiUUID` is wrong.** They must be the pair the server has registered together — the app id decrypts the payload, and the account UUID inside it must match the one on record for that app.
2. **Clock skew.** The key changes every 256 seconds and the server tries one bucket either side, so more than roughly four minutes of drift is fatal. Check NTP first when a previously working deployment starts failing.
3. **A replayed request.** Each nonce is single-use for 15 minutes. This should only appear under a retry that resends a captured body verbatim.

## Stale instances

`createToken()` handles the ordinary case for you: on 401, 403, or 409, it discards the stored instance, handshakes again, and retries once. A second failure is thrown.

An instance goes stale when another process handshakes for the same app and fingerprint, since that rotates the instance and invalidates the previous one. If you see continuous rotation, two processes are sharing a store that does not persist — see [Configuration](configuration.md#instance-store).

Force a fresh handshake explicitly after a site move or credential change:

```php
$client->forgetInstance();
```

## Decryption failures

A sealed reply that will not open throws `ValidakeyException` rather than `ApiException`, since the server answered successfully and the client could not read it:

> Could not decrypt the Validakey instance handshake reply. This usually means the client and server clocks differ by more than the key rotation window, or the credentials do not match.

Same checklist as a failed handshake: clocks, then credentials.

Instance Entity card calls (`instancePaymentStatus()`, `attachInstanceCard()`, and the rest) do **not** throw on a malformed body. They return `InstancePaymentResponse` with `isOk() === false` and `errorCode()` `invalid_response`, so a WordPress plugin is not taken down by an HTML error page from `/v1/p/`. Token minting still throws `ApiException` — a vKey must not be invented locally.

## Client-side errors (before HTTP)

Calling a Bearer-authenticated method without configuring `apiPKey`:

```php
// Throws ValidakeyException: "apiPKey is required for this Validakey API call."
$client->getBillingStatus();
```

Invalid config at construction:

```php
// Throws InvalidArgumentException
new ValidakeyConfig(baseUrl: '', apiUUID: 'uuid', userAppId: 'my-app');
```

## Results that are not exceptions

A vKey being invalid is an answer, not a failure. `verifyToken()`, `renewToken()` and `deleteToken()` all return normally and report it through the response:

```php
$check = $client->verifyToken($token);

if (! $check->isValid()) {
    // revoked, expired, depleted, not_found
    echo $check->reason();
}
```

The same holds for a declined card on `attachInstanceCard()` or a priced renewal: the call succeeds, and `isOk()` or `isValid()` is false with the decline code alongside. A decline is something to show the customer, not a fault in the request, and throwing would force every caller to catch an exception on the ordinary path.

What *does* throw is the request failing to authenticate, the account not being able to mint at all, or a reply that will not open.

## Logging best practices

- Log `errorCode` and `httpStatus`, not full request bodies containing secrets.
- Do not log `apiPKey`, `userAppId`, instance ids, or issued tokens at info/debug levels in production.
- Sealed request bodies are safe to log, but they are also useless for debugging without the key.
- Wrap Validakey calls at application boundaries so user-facing messages stay generic.

## Retries

The only automatic retry is the stale-instance one described above. For transient 503 errors, implement exponential backoff in your application layer if appropriate.

Do not retry a sealed request by resending the same body: its nonce is spent, so the second attempt is rejected as a replay. Call the client method again instead and let it seal a fresh envelope.
