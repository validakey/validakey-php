# Changelog

## 1.8.0

WordPress license integration is one register call plus feature gates.

- `License::allows()` / `lastCheck()` / `revalidate()` / `requireGranted()` —
  gate on the last verification without a network call; refresh via
  `revalidate()` (or live `status()` / `isGranted()`)
- `LicenseSnapshot` + `LicenseCheckStore` / `InMemoryLicenseCheckStore` /
  `OptionsLicenseCheckStore` (`{slug}_validakey_license_checks`)
- `Validakey\WordPress\LicenseBootstrap::register()` — wires `LicensePanel`
  POST handling, optional License submenu, admin notice when unlicensed,
  and WP-Cron revalidation
- `LicenseBootstrap::allows()` / `license()` / `renderPanel()` /
  `deactivate($slug)`
- `LicenseScheduler` — `{slug}_validakey_revalidate` (default `twicedaily`)
- `PluginClientFactory::license()` persists checks and accepts
  `revalidateInterval` / `failClosed`
- `LicenseRequiredException` when `requireGranted()` fails

See `docs/wordpress-plugin-integration.md`.

## 1.7.0

The handshake requires a subject. When none is configured, this client
assigns a random UUIDv4 Subject ID and persists it in the instance store.
Pass `subject` / `VALIDAKEY_SUBJECT` / `forSubject()` to name the end
customer yourself. The WordPress `license()` helper still defaults to the
site host when `home_url()` is available.

## 1.6.2

- `Validakey\WordPress\LicensePanel` — admin status table + Request button
  (`render`, `handleRequest`). Capability-gated; not a public shortcode.
  RoundPeg’s License tab is a thin wrapper around it.

## 1.6.1

License helpers expose identifying prefixes for admin UIs without revealing
the full secrets:

- `ValidakeyClient::storedInstanceId()` — peek the store; never handshakes
- `License::tokenPrefix()` / `instancePrefix()` — first
  {@see \Validakey\Envelope::PREFIX_LEN} characters (wire lookup width)
- `License::tokenLocator()` — `{IE prefix}_{vKey prefix}` for obfuscated admin display

## 1.6.0

The usual plugin license flow is now a few lines: persist a vKey, check it,
mint one if needed.

- `CreateTokenRequest::free()` — type 1 transactional vKey (no expiry, no price)
- `Validakey\License` — `status()`, `isGranted()`, `request()`, `forget()` over a `TokenStore`
- `InMemoryTokenStore` / `Validakey\Instance\TokenStore`
- `OptionsTokenStore` — `{pluginSlug}_validakey_tokens`, autoload off
- `PluginClientFactory::license()` — client + token store + free spec. When
  `VALIDAKEY_SUBJECT` is unset and `home_url()` exists, the IE subject is the
  site host
- `ValidakeyClient::subject()` and `storeKey()` are public so a helper can
  key the vKey the same way as the instance id

`request()` is idempotent while the stored vKey is still valid. Status with no
stored token stays local and does not call the API.

See `docs/wordpress-plugin-integration.md`.

## 1.5.1

Malformed HTTP bodies no longer take down the host application.

- JSON salvage: PHP notices/BOM in front of a JSON object are stripped
- Non-JSON 2xx is `ApiException` with `invalid_response`, including a body snippet
- IE card methods return `InstancePaymentResponse` (`ok: false`) instead of throwing
  on a malformed `/v1/p/` reply. Handshake `payment_required` still throws.

## 1.5.0

### WordPress helpers (optional namespace)

Thin adapters under `Validakey\WordPress\` for plugin environments. The core
client stays WordPress-free; these classes call `get_option` /
`update_option` only when you use them.

- `OptionsInstanceStore` — options-table persistence with autoload off
- `PluginClientFactory` — assemble `VALIDAKEY_*` constants into a client + options store
- `configArrayFromConstants($namespace)` — namespaced `const` first, then global `define()`
- `make(...)` — option key defaults to `{pluginSlug}_validakey_instances`; omitted slug
  is resolved by `pluginSlug_Guess()`; omitted `constantsNamespace` by
  `callerNamespace_Guess()` (caller’s PHP namespace)

License-path credentials (`BASE_URL`, `API_UUID`, `USER_APP_ID`) are expected to
ship with the plugin. Do not distribute `VALIDAKEY_API_PKEY`.

See `docs/wordpress-plugin-integration.md`.

## 1.4.0

The server grew license validation, customer-funded vKeys, a monthly
subscription for account holders, and per-subject Instance Entities with
machine locks and subject transfer. This release catches the client up, and
renames the account credential to say what it is.

Breaking, with no deprecation shim. Nothing was deployed on 1.3.

### The account credential is the private key

`apiSecret` is now `apiPKey`, and `VALIDAKEY_API_SECRET` is
`VALIDAKEY_API_PKEY`. `ValidakeyConfig::hasSecret()` is `hasPKey()`.

The rename is the point, not cosmetic. "Secret" reads like something every
call needs; this credential is for reading and changing *your own account*,
and nothing on the license path touches it. Software you distribute should
not carry it at all.

`BillingStatusResponse::apiSecret()` is removed. It read a field the server
stopped returning, so it had been silently answering `null`.

### Subjects, locks and transfer

An Instance Entity is keyed by end-customer *subject*, not by fingerprint
alone. The fingerprint is evidence of which machine last spoke.

- `ValidakeyConfig::$subject` / `VALIDAKEY_SUBJECT` / `withSubject()`
- `ValidakeyClient::forSubject()` — sibling client sharing transport and store
- Handshake M1 carries a length-prefixed subject; omitted subjects default to
  the fingerprint on the server (single-tenant compatibility)
- Instance store keys are `appId|subject|fingerprint`
- `InstanceResponse` exposes subject, lock, and counter fields when present
- `transferSubject()` — sealed `POST /v1/t/`; requires an account-opened
  transfer window. On success the client rebuckets the stored instance id
- `instance_locked` is surfaced rather than treated as a stale instance to retry

### Validation, renewal and revocation are real

`verifyToken()` and `deleteToken()` were stubs marked `@experimental`, sending
a bare token as a query parameter to an endpoint that returned a placeholder
string. They now seal a request under the instance id like every other
token-path call.

- `verifyToken(string $token, bool $consume = false)` — read-only unless asked
  to spend a use. A license check on startup and an actual use are different
  events, and only the second should draw down a use-limited vKey.
- `renewToken(string $token, ?int $duration, ?int $uses)` — new. Adds time or
  uses without changing the vKey value, so copies already deployed keep
  working.
- `deleteToken(string $token)` — now `DELETE /v1/v/` with a sealed body.
  Idempotent, and the record survives revocation.

`TokenVerifyResponse` is now a typed result: `isValid()`, `reason()`,
`expiresAt()`, `usesRemaining()`, `wasRevoked()`. The old `body` / `isJson` /
`isExpiredPlaceholder()` shape existed only to tolerate the stub.

Note the nullables. `expiresAt()` is `null` for a vKey that never expires and
`usesRemaining()` is `null` for one that is not use-limited — because `0` is a
real answer meaning depleted, and collapsing the two would report an unlimited
vKey as exhausted.

Validation is never refused because the selling account's billing lapsed. A
customer who paid for a license keeps it.

### Your customer's card, on the sealed channel

The customer running your software pays for their own vKeys. Four new methods,
all sealed under the instance id, so the customer never needs a private key:

- `instancePaymentStatus()`
- `attachInstanceCard(AttachCardRequest)` — for applications with a web
  frontend that can run Square's Web Payments SDK
- `requestInstancePaymentLink()` — for CLI, desktop and headless clients:
  returns a single-use URL, valid fifteen minutes, that can do nothing but
  attach a card to that one instance
- `detachInstanceCard()`

Card numbers still go from the customer's browser to Square directly. Neither
this library nor the Validakey server handles one.

Minting a priced vKey with no customer card on file now fails with
`ie_card_required` (HTTP 402), distinct from your own account's
`payment_required`, so an application can prompt the right person.

### Renames that say whose card it is

`attachCard()` and `detachCard()` are now `attachAccountCard()` and
`detachAccountCard()`. With two payers in the model, a bare `attachCard()`
does not say which one it means.

### Instance rotation

`rotateInstance()` is new, for `POST /v1/r/`. Sealed under the id being
replaced, which is the whole authorization. Prefer it over a repeat
`getInstanceToken()`: the handshake flags a rotation that closely follows
activity as a possible cloned application id, and an explicit rotation is not
flagged.

### Other

- `CreateTokenRequest` gains `noExpiry`, for a vKey that only revocation can
  end. It has to be asked for explicitly rather than inferred from a zero
  duration, so a misconfigured value cannot become a perpetual license.
- `getBillingConfig()` now sends the private key. It always required it
  server-side; the client was omitting it and the call was failing.

## 1.3.0

Instance handshake, sealed token minting, account and billing endpoints.
