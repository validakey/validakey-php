# API reference

This document describes the PHP client API and the Validakey v1 HTTP endpoints it calls.

## ValidakeyClient

Main entry point. Construct with `ValidakeyConfig` and optional custom `HttpTransport`.

The account UUID is never part of the URL path. How a call authenticates depends on the endpoint:

- **Token path** (`/v1/i/`, `/v1/r/`, `/v1/t/`, `/v1/m/`, `/v1/v/`, `/v1/p/`) — the request body is a single encrypted field. No identifiers travel in cleartext, and no account credential is involved. See [Access and authentication](access-and-auth.md).
- **Account path** (`/v1/u/`, `/v1/billing/*`) — `Bearer apiPKey`, plus the cleartext **application context** (account UUID, user app id, fingerprint) in headers and body/query fields. See [Configuration](configuration.md#application-context).

Everything to do with licenses is on the token path. The private key exists for reading and changing your own account, and software you distribute should not carry it.

#### `applicationContext(): ApplicationContext`

Returns the resolved context for the current client configuration.

```php
$ctx = $client->applicationContext();
echo $ctx->userAppId;
echo $ctx->host;
echo $ctx->apiUUID;
```

### Instance methods

#### `getInstanceToken(): InstanceResponse`

Performs the handshake and returns a freshly granted instance.

- **HTTP:** `POST /v1/i/`
- **Auth:** Sealed with `userAppId`
- **Precondition:** Validakey account must have active billing (card on file)

```php
$instance = $client->getInstanceToken();
echo $instance->instanceId;
echo $instance->fingerprint;
echo $instance->subject;
```

This **rotates** the instance server-side, invalidating whatever was issued before. Prefer `createToken()`, which reuses the stored instance and handshakes only when it must.

---

#### `instanceId(): string`

The stored instance id, handshaking only if none is held.

```php
$id = $client->instanceId();
```

---

#### `rotateInstance(): InstanceResponse`

Replaces the current instance id with a fresh one and stores it.

- **HTTP:** `POST /v1/r/`
- **Auth:** Sealed with the instance id being replaced
- **Precondition:** none. Rotation is not gated on billing, because refusing it to a lapsed account would only keep a stale credential alive longer.

```php
$instance = $client->rotateInstance();
```

Prefer this over a repeat `getInstanceToken()` for routine rotation. The handshake flags a rotation that closely follows activity as a possible cloned application id; an explicit rotation is not flagged, because the caller had to hold the live instance id to ask for it.

The reply is sealed under the **old** id, so a client that loses the response still holds the key needed to open a retry.

---

#### `forSubject(?string $subject): ValidakeyClient`

Returns a sibling client scoped to one end-customer subject, sharing the same transport and instance store.

```php
$alpha = $client->forSubject('customer-42');
$beta  = $client->forSubject('customer-99');
```

Each subject gets its own stored instance bucket. When subject is omitted, the client assigns a random Subject ID and persists it with the instance id.

---

#### `transferSubject(string $subject): InstanceResponse`

Transfers the current Instance Entity to a new subject and returns the fresh instance id.

- **HTTP:** `POST /v1/t/`
- **Auth:** Sealed with the current instance id
- **Precondition:** the account holder must first authorize a one-shot transfer window through `PATCH /v1/instance/`

```php
$moved = $client->forSubject('old-customer')->transferSubject('new-customer');
echo $moved->instanceId;
```

The client removes the old subject's stored instance id and stores the fresh one under the destination subject, so `forSubject('new-customer')` can reuse it immediately.

---

#### `forgetInstance(): void`

Discards the stored instance so the next call handshakes again. Useful after moving a site or changing credentials.

---

#### `machineFingerprint(): string`

This client's fingerprint, `machineId() . '_' . host`. Memoised, since the underlying reads touch the filesystem.

---

#### `ValidakeyClient::machineId(): string` (static)

First readable of `/sys/class/dmi/id/product_uuid`, `/etc/machine-id`, `/var/lib/dbus/machine-id`; falls back to a constant when none are readable.

---

#### `ValidakeyClient::modTime(string $keyId, int $bits = 8): int` (static)

The obfuscated timestamp for a key id. Exposed for diagnostics; the protocol definition lives in `Validakey\Envelope`.

---

### Token methods

#### `createToken(CreateTokenRequest $request): TokenResponse`

Creates a new access token.

- **HTTP:** `POST /v1/m/`
- **Auth:** Sealed with the instance id
- **Precondition:** Validakey account must have active billing (card on file)

```php
$token = $client->createToken(new CreateTokenRequest(
    duration: 3600,
    basisCents: 999,
));
```

The request is sealed with the client's instance id, performing a handshake first if none is stored. If the server has rotated the instance away, the stored one is discarded and the handshake is retried once.

**Returns:** `TokenResponse` with `token`, `created`, `expiresAt`, and full `raw` payload.

`CreateTokenRequest::free()` is the type 1 shortcut: `noExpiry: true`, duration 0, no price fields.

---

#### `Validakey\License`

Application helper over a client, a `TokenStore`, and a `LicenseCheckStore`.
WordPress plugins typically get one from `LicenseBootstrap::register()` or
`PluginClientFactory::license()` rather than constructing it.

```php
$license = PluginClientFactory::license();

if (! $license->allows()) {
    // gate features — no network
}

$license->revalidate();      // cron / forced refresh
$check = $license->status(); // live verify + update snapshot
$check->isGranted();
$check->reason();      // none, or a verify reason
$check->expiresAt();   // null when the vKey never expires
$check->subject;

$license->tokenPrefix();     // first 8 chars — safe for admin display
$license->instancePrefix();  // first 8 of the stored IE id; null if none yet
$license->tokenLocator();    // "{IE prefix}_{vKey prefix}" for obfuscated display
$license->requireGranted();  // throws LicenseRequiredException when allows() is false
```

- `allows()` / `lastCheck()` read the local snapshot only.
- `status()` with no stored vKey is local (`reason: none`) and does not call the API; with a vKey it verifies live and refreshes the snapshot.
- `request()` mints with the configured spec (default `CreateTokenRequest::free()`) and persists the vKey and a granted snapshot. A still-valid stored vKey is not minted again.
- Do not print `token()` in HTML. Prefer `tokenLocator()` (or the separate prefixes) for admin identifying info.

#### `Validakey\WordPress\LicenseBootstrap`

One-call WordPress wiring: panel POST handling, optional submenu, admin notice, cron.

```php
LicenseBootstrap::register(array(
    'plugin_slug' => 'myplugin',
    'settings_page' => 'myplugin',
    'redirect_url' => admin_url('options-general.php?page=myplugin&tab=license'),
));
LicenseBootstrap::allows();
LicenseBootstrap::renderPanel();
LicenseBootstrap::deactivate('myplugin'); // deactivation hook
```

#### `Validakey\WordPress\LicensePanel`

Admin UI for a {@see License}: status table and Request button. Prefer `LicenseBootstrap` unless you need a custom shell.

```php
LicensePanel::handleRequest($license, array('redirect_url' => $url)); // admin_init
LicensePanel::render($license, array('configured' => true));          // settings tab
```

Pass `configured: false` and `$license = null` when constants are missing. Default capability is `manage_options`. Not a public shortcode.

---

#### `verifyToken(string $token, bool $consume = false): TokenVerifyResponse`

Checks whether a vKey is still good.

- **HTTP:** `POST /v1/v/`
- **Auth:** Sealed with the instance id
- **Precondition:** none. Validation is never gated on billing, so a customer who paid for a license keeps it even if the selling account lapses.

```php
$check = $client->verifyToken('ABCDEF1234567890');

$check->isValid();        // bool
$check->reason();         // revoked, expired, depleted, not_found
$check->expiresAt();      // ?int — null when the vKey never expires
$check->usesRemaining();  // ?int — null when it is not use-limited
```

Read-only by default. Pass `$consume` to spend one use of a use-limited vKey:

```php
$client->verifyToken($token, consume: true);
```

Keep the two apart. "Is this license valid" is asked on every startup; "the holder just used it" happens once per use. Consuming on the first would drain a use-limited vKey on health checks alone.

The decrement happens inside the `WHERE` clause server-side, so two machines spending the last use at the same moment cannot both succeed.

---

#### `renewToken(string $token, ?int $duration = null, ?int $uses = null): TokenVerifyResponse`

Adds time, uses, or both, without changing the vKey value — copies already deployed keep working.

- **HTTP:** `POST /v1/v/` with `action: renew`
- **Auth:** Sealed with the instance id

```php
$client->renewToken($token, duration: 2592000);
$client->renewToken($token, uses: 500);
```

Where the vKey carries a price, the renewal is charged to the customer's card. A decline comes back as `isValid() === false` with the decline code in `reason()`, not as an exception: a declined card is something to show the customer, not a fault in the call.

---

#### `deleteToken(string $token): TokenVerifyResponse`

Revokes a vKey. Idempotent.

- **HTTP:** `DELETE /v1/v/`
- **Auth:** Sealed with the instance id

```php
$client->deleteToken('ABCDEF1234567890');
```

The record survives revocation — usage and billing history reference it, so it is marked rather than removed. Revoking is the only thing that can end a vKey minted with no expiry.

---

### Instance Entity payment methods

The Instance Entity is the customer running your software. They hold no Validakey account and no private key, but they do hold an instance id, so their card is collected over the same sealed channel and stored against their instance.

Card numbers go from the customer's browser to Square directly. Neither this library nor the Validakey server ever handles one; only the resulting `source_id` passes through.

All four methods use `POST /v1/p/`, sealed with the instance id, and return `InstancePaymentResponse`. A malformed (non-JSON) reply is reported as `isOk() === false` / `errorCode() === 'invalid_response'` rather than thrown.

#### `instancePaymentStatus(): InstancePaymentResponse`

```php
$status = $client->instancePaymentStatus();
$status->hasCard();     // bool
$status->cardBrand();   // e.g. "VISA"
$status->cardLast4();
```

---

#### `attachInstanceCard(AttachCardRequest $request): InstancePaymentResponse`

For applications with a web frontend: collect a `source_id` there with Square's Web Payments SDK and seal it.

```php
$result = $client->attachInstanceCard(new AttachCardRequest(
    sourceId: 'cnon:card-nonce-from-square-sdk',
    billingFields: ['postal_code' => '90210', 'country' => 'US'],
));

if (! $result->isOk()) {
    echo $result->errorMessage(); // e.g. a decline
}
```

---

#### `requestInstancePaymentLink(): InstancePaymentResponse`

For CLI, desktop and other headless clients with no browser to host Square's form in. Print the URL; the customer opens it anywhere.

```php
$link = $client->requestInstancePaymentLink();
echo $link->paymentUrl();
echo $link->expiresAt();
```

The link is single-use, expires in fifteen minutes, and can do nothing but attach a card to that one instance. It is not a credential for the token path and cannot be exchanged for an instance id.

---

#### `detachInstanceCard(): InstancePaymentResponse`

```php
$client->detachInstanceCard();
```

The Square customer is kept, so re-entering a card later lands on the same customer rather than scattering duplicates.

---

Minting a vKey that carries a price fails with `ie_card_required` (HTTP 402) when the customer has no card on file, so your application can prompt rather than surface a generic failure. The check runs before anything is minted. Your own card is deliberately not a fallback — silently billing you for your customer's purchase would be worse than failing the request.

---

### Account methods

These act on **your** account, not your customer's, and are the only calls that read `apiPKey`. Keep them out of software you distribute.

#### `getUserInfo(): UserInfoResponse`

Returns API user ID and billing profile.

- **HTTP:** `GET /v1/u/`
- **Auth:** Bearer (`apiPKey`)

```php
$user = $client->getUserInfo();
echo $user->apiUserId;
print_r($user->billing);
```

---

### Billing methods

These cover the **account holder's** card, which the monthly service fee and the per-event platform fee are charged to. For the customer's card, see [Instance Entity payment methods](#instance-entity-payment-methods).

#### `getBillingConfig(): BillingConfigResponse`

Square Web Payments SDK configuration for card collection UI.

- **HTTP:** `GET /v1/billing/config`
- **Auth:** Bearer

```php
$config = $client->getBillingConfig();
echo $config->applicationId();
echo $config->locationId();
echo $config->isSandbox() ? 'sandbox' : 'production';
```

---

#### `getBillingStatus(): BillingStatusResponse`

Current billing profile and card status.

- **HTTP:** `GET /v1/billing/status`
- **Auth:** Bearer

```php
$status = $client->getBillingStatus();
$status->hasCard();         // bool
$status->billingStatus();   // e.g. "active", "none"
```

---

#### `ensureCustomer(): array`

Ensures a Square customer record exists for the API user.

- **HTTP:** `POST /v1/billing/ensure_customer`
- **Auth:** Bearer

```php
$data = $client->ensureCustomer();
```

---

#### `attachAccountCard(AttachCardRequest $request): array`

Attaches the account holder's card from Square Web Payments (`source_id` nonce). Attaching a card is also what enrolls the account in monthly dues; the first period is granted rather than charged.

- **HTTP:** `POST /v1/billing/attach`
- **Auth:** Bearer

```php
use Validakey\Request\AttachCardRequest;

$result = $client->attachAccountCard(new AttachCardRequest(
    sourceId: 'cnon:card-nonce-from-square-sdk',
    billingFields: [
        'given_name'  => 'Jane',
        'family_name' => 'Doe',
        'postal_code' => '90210',
        'country'     => 'US',
    ],
));
```

Supported billing field keys: `given_name`, `family_name`, `postal_code`, `country`, `address_line_1`, `locality`, `administrative_district_level_1`.

---

#### `detachAccountCard(): array`

Removes the account holder's card on file.

- **HTTP:** `POST /v1/billing/detach`
- **Auth:** Bearer

```php
$result = $client->detachAccountCard();
```

---

## Request types

### CreateTokenRequest

| Property | Type | Default | API field |
|----------|------|---------|-----------|
| `duration` | `int` | `3600` | `duration` (seconds) |
| `noExpiry` | `bool` | `false` | `no_expiry` — mint a vKey that never expires |
| `expiresAt` | `?int` | `null` | `expires_at` (Unix timestamp) |
| `uses` | `?int` | `null` | `uses` (max use count) |
| `recurrence` | `?string` | `null` | `recurrence` (e.g. `monthly`, `daily`) |
| `autoRenew` | `?bool` | `null` | `auto_renew` |
| `basisCents` | `?int` | `null` | `basis_cents` |
| `amount` | `?float` | `null` | `amount` (USD dollars) |
| `costUsd` | `?float` | `null` | `cost_USD` |

Only one price field is sent; priority is `basisCents`, then `amount`, then `costUsd`.

`noExpiry` must be asked for explicitly. Reading a zero duration as "forever" would turn a misconfigured value into a perpetual license.

The five documented vKey types are all combinations of these fields:

| Type | Request |
|---|---|
| 1 Transactional | `CreateTokenRequest::free()` or `noExpiry: true` |
| 2 Limited Time | `duration`, or `expiresAt` |
| 3 Limited Use | `uses` |
| 4 Subscription Time | `duration`, `autoRenew: true`, `recurrence` |
| 5 Subscription Use | `uses`, `autoRenew: true`, and a price |

### AttachCardRequest

| Property | Type | Description |
|----------|------|-------------|
| `sourceId` | `string` | Square card nonce from Web Payments SDK |
| `billingFields` | `array<string,string>` | Optional billing address / name fields |

---

## Response types

### TokenResponse

| Property | Type | Description |
|----------|------|-------------|
| `token` | `string` | Plaintext access token |
| `created` | `int` | Unix timestamp |
| `expiresAt` | `int` | Unix timestamp |
| `raw` | `array` | Full server JSON (includes `out` and `db` keys) |

### BillingStatusResponse

Helper methods: `billingStatus()`, `hasCard()`. Raw data in `$response->data`.

### UserInfoResponse

| Property | Type |
|----------|------|
| `apiUserId` | `string` |
| `billing` | `array<string,mixed>` |

### InstanceResponse

| Property | Type | Description |
|----------|------|-------------|
| `instanceId` | `string` | The granted instance id (UUIDv4). Arrives encrypted; the client decrypts it. |
| `fingerprint` | `string` | The fingerprint it was granted for. |
| `subject` | `string` | The end-customer subject. Configured `subject` when set; otherwise the random Subject ID this client assigned. |
| `machineLocked` | `?bool` | Present when the server includes lock state. |
| `transferEligible` | `?bool` | Present when the server includes transfer-window state. |
| `transferExpires` | `?string` | Present when the server includes transfer expiry. |
| `machineChanges` | `?int` | Present when the server includes machine-move count. |
| `subjectTransfers` | `?int` | Present when the server includes subject-transfer count. |

### BillingConfigResponse

Helper methods: `applicationId()`, `locationId()`, `isSandbox()`. The `applicationId()` here is **Square’s** application ID for the Web Payments SDK — not your Validakey `user_app_id`.

### TokenVerifyResponse

Covers validate, renew and revoke, which the server answers in one shape.

| Method | Type | Description |
|--------|------|-------------|
| `isValid()` | `bool` | Authoritative. Everything else is detail. |
| `reason()` | `string` | Why not: `revoked`, `expired`, `depleted`, `not_found`, or a billing failure code from a renewal. Empty when valid. |
| `expiresAt()` | `?int` | Unix expiry, `null` when the vKey never expires |
| `usesRemaining()` | `?int` | Remaining uses, `null` when not use-limited. `0` means depleted, which is not the same thing. |
| `wasRevoked()` | `bool` | Set on a revoke reply |

Raw payload in `$response->data`.

### InstancePaymentResponse

| Method | Type | Description |
|--------|------|-------------|
| `isOk()` | `bool` | Whether the operation succeeded |
| `errorCode()` | `string` | Failure code, e.g. a decline |
| `errorMessage()` | `string` | Human-readable failure |
| `hasCard()` | `bool` | Card on file for this instance |
| `cardBrand()` | `string` | e.g. `VISA` |
| `cardLast4()` | `string` | Last four digits |
| `paymentUrl()` | `?string` | Hosted form URL, on a link request only |
| `expiresAt()` | `?int` | When that link stops working |

Raw payload in `$response->data`.

---

## HTTP endpoint summary

| Method | Path | Auth | Client method |
|--------|------|------|---------------|
| POST | `/v1/i/` | Sealed (`userAppId`) | `getInstanceToken` |
| POST | `/v1/r/` | Sealed (instance id) | `rotateInstance` |
| POST | `/v1/t/` | Sealed (instance id) | `transferSubject` |
| POST | `/v1/m/` | Sealed (instance id) | `createToken` |
| POST | `/v1/v/` | Sealed (instance id) | `verifyToken`, `renewToken` |
| DELETE | `/v1/v/` | Sealed (instance id) | `deleteToken` |
| POST | `/v1/p/` | Sealed (instance id) | `instancePaymentStatus`, `attachInstanceCard`, `detachInstanceCard`, `requestInstancePaymentLink` |
| GET | `/v1/u/` | Bearer | `getUserInfo` |
| GET | `/v1/billing/config` | Bearer | `getBillingConfig` |
| GET | `/v1/billing/status` | Bearer | `getBillingStatus` |
| POST | `/v1/billing/ensure_customer` | Bearer | `ensureCustomer` |
| POST | `/v1/billing/attach` | Bearer | `attachAccountCard` |
| POST | `/v1/billing/detach` | Bearer | `detachAccountCard` |

`GET /v1/pay/?t=…` is the server-hosted card form the link from `requestInstancePaymentLink()` points at. A human opens it in a browser; no client method calls it.

---

## Response envelopes

**Sealed endpoints** (`/v1/i/`, `/v1/r/`, `/v1/m/`, `/v1/v/`, `/v1/p/`) return the payload encrypted in a single field:

```json
{ "out": { "q": "9b49fe4744cbe0f4a1c2…" } }
```

Decrypted, a token reply is an 8-byte nonce followed by:

```json
{
  "token": "ABCDEF…",
  "created": 1700000000,
  "expires_at": 1700003600
}
```

The client does this unwrapping for you; `TokenResponse` is built from the decrypted payload.

**Billing and user info** return a success envelope:

```json
{
  "status": "success",
  "data": { … }
}
```

**Errors** return:

```json
{
  "ERROR": "Human-readable message",
  "CODE": "machine_code",
  "DATA": { }
}
```

See [Error handling](error-handling.md) for exception mapping.
