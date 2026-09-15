# Validakey PHP Client

Framework-agnostic PHP client for the [Validakey](https://validakey.com) v1 API.

Install via Composer:

```bash
composer require validakey/validakey-php
```

## Documentation

Full usage guides are in the **[docs/](docs/README.md)** folder:

- [Getting started](docs/getting-started.md)
- [Configuration](docs/configuration.md)
- [Access and authentication](docs/access-and-auth.md)
- [API reference](docs/api-reference.md)
- [Error handling](docs/error-handling.md)
- [WordPress plugin integration](docs/wordpress-plugin-integration.md) — `LicenseBootstrap::register()`, `allows()`, admin panel, cron

## Requirements

- PHP 8.1+
- Symfony HttpClient (installed automatically)

## Configuration

Never commit secrets. Pass credentials from your application environment or plugin settings:

```php
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\Request\CreateTokenRequest;

$config = new ValidakeyConfig(
    baseUrl: getenv('VALIDAKEY_BASE_URL') ?: 'https://example.validakey.host/v1',
    apiUUID: getenv('VALIDAKEY_API_UUID') ?: 'your-account-uuid-here',
    userAppId: getenv('VALIDAKEY_USER_APP_ID') ?: 'your-user-app-id-here',
    apiPKey: getenv('VALIDAKEY_API_PKEY') ?: null,
    subject: getenv('VALIDAKEY_SUBJECT') ?: null,
);

$client = new ValidakeyClient($config);
```

Or load from environment variables:

```php
$config = ValidakeyConfig::fromEnv(); // VALIDAKEY_BASE_URL, VALIDAKEY_API_UUID, VALIDAKEY_USER_APP_ID, VALIDAKEY_HOST, VALIDAKEY_API_PKEY, VALIDAKEY_TIMEOUT
```

Copy `.env.example` to `.env` locally for development (do not commit `.env`). See [Configuration](docs/configuration.md) for details.

`apiPKey` is your account **private key**, and most applications should leave it out. Only the account endpoints — user info and the account holder's own billing — read it. Everything to do with licenses (minting, validating, renewing, revoking, and collecting your customer's card) is authenticated by the instance handshake instead.

Do not ship the private key in software you distribute, and do not commit it. It can change billing details and read usage for the whole account, and no license operation needs it.

## Instances

Token requests are not authenticated by a shared secret in the request. On first use the client performs a handshake, sealed with your `userAppId`, and the server grants an **instance id** scoped to this application, this subject, and this machine. That instance id keys every later token request, and neither it nor your account UUID travels in cleartext.

Give the client somewhere to keep the instance id, or it will handshake once per PHP process:

```php
use Validakey\Instance\FileInstanceStore;

$client = new ValidakeyClient(
    $config,
    null,
    new FileInstanceStore('/var/lib/myapp/validakey-instance.json'),
);
```

A repeat handshake rotates the instance and invalidates the previous one, so a persistent store matters for more than performance. See [Access and authentication](docs/access-and-auth.md) for the protocol and its trade-offs.

To rotate deliberately, use `rotateInstance()` rather than a repeat handshake. It is sealed under the id being replaced, so only a holder of the live instance id can rotate it, and the server does not treat it as a possible cloned application:

```php
$client->rotateInstance();
```

For multi-tenant applications, fork the client per end-customer subject:

```php
$customerClient = $client->forSubject('customer-42');
```

If you omit `subject`, the client assigns a random Subject ID and persists it in the instance store. The handshake requires a subject; the library supplies one so a single-tenant app does not have to.

## Usage

See [Getting started](docs/getting-started.md) and [API reference](docs/api-reference.md) for complete examples. Quick start:

### Create a token

```php
$token = $client->createToken(new CreateTokenRequest(
    duration: 3600,
    basisCents: 999,
));

echo $token->token;
```

### Validate, renew and revoke

```php
$check = $client->verifyToken($token->token);

if (! $check->isValid()) {
    exit($check->reason()); // revoked, expired, depleted, not_found
}

echo $check->expiresAt();     // null when the vKey never expires
echo $check->usesRemaining(); // null when it is not use-limited
```

Validation is read-only. Pass `consume: true` to spend one use of a use-limited vKey — a license check on startup and an actual use are different events, and only the second should draw down an allowance:

```php
$client->verifyToken($token->token, consume: true);
```

Renewing adds time or uses without changing the vKey value, so copies already deployed keep working. Revoking is what ends a vKey that has no expiry:

```php
$client->renewToken($token->token, duration: 2592000);
$client->renewToken($token->token, uses: 500);
$client->deleteToken($token->token);
```

Validation is never refused because the account's own billing lapsed. A customer who paid for a license keeps it.

### Collect your customer's card

The customer running your software — their **Instance Entity** — pays for their own vKeys, on their own card, stored against their instance. Card numbers go from their browser to Square directly; neither this library nor the Validakey server ever sees one.

If your application has a web frontend, collect a `source_id` there with Square's Web Payments SDK and seal it:

```php
use Validakey\Request\AttachCardRequest;

$client->attachInstanceCard(new AttachCardRequest(
    sourceId: 'cnon:card-nonce-from-square-sdk',
    billingFields: ['postal_code' => '90210', 'country' => 'US'],
));
```

For a CLI or desktop application with no browser to host that form in, ask for a link and print it:

```php
$link = $client->requestInstancePaymentLink();

echo 'Enter your card at: ' . $link->paymentUrl();
```

The link is single-use, expires in fifteen minutes, and can do nothing but attach a card to that one instance.

```php
$status = $client->instancePaymentStatus(); // hasCard(), cardBrand(), cardLast4()
$client->detachInstanceCard();
```

Minting a vKey that carries a price fails with `ie_card_required` (HTTP 402) when the customer has no card on file, so you can prompt rather than guess. Your own card is deliberately not a fallback.

### Your account (private key)

These act on **your** account rather than your customer's, and are the only calls that read the private key. Keep them out of anything you distribute.

```php
$status = $client->getBillingStatus();
$config = $client->getBillingConfig();
$user   = $client->getUserInfo();
```

Your card covers the monthly service fee and the per-event platform fee:

```php
$client->attachAccountCard(new AttachCardRequest(sourceId: 'cnon:...'));
$client->detachAccountCard();
```

## Error handling

```php
use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;

try {
    $client->createToken(new CreateTokenRequest());
} catch (PaymentRequiredException $e) {
    // HTTP 402 — attach a billing card first
} catch (ApiException $e) {
    echo $e->getMessage();
    echo $e->errorCode;
    echo $e->httpStatus;
}
```

## API routes

| Method | Path | Auth | Method on the client |
|--------|------|------|------|
| POST | `/v1/i/` | Sealed with `userAppId` | `getInstanceToken` |
| POST | `/v1/r/` | Sealed with instance id | `rotateInstance` |
| POST | `/v1/t/` | Sealed with instance id | `transferSubject` |
| POST | `/v1/m/` | Sealed with instance id | `createToken` |
| POST | `/v1/v/` | Sealed with instance id | `verifyToken`, `renewToken` |
| DELETE | `/v1/v/` | Sealed with instance id | `deleteToken` |
| POST | `/v1/p/` | Sealed with instance id | `instancePaymentStatus`, `attachInstanceCard`, `detachInstanceCard`, `requestInstancePaymentLink` |
| GET | `/v1/u/` | Private key | `getUserInfo` |
| GET | `/v1/billing/config` | Private key | `getBillingConfig` |
| GET | `/v1/billing/status` | Private key | `getBillingStatus` |
| POST | `/v1/billing/ensure_customer` | Private key | `ensureCustomer` |
| POST | `/v1/billing/attach` | Private key | `attachAccountCard` |
| POST | `/v1/billing/detach` | Private key | `detachAccountCard` |

Only the private-key routes carry cleartext identity headers (`X-Validakey-User-App-Id`, `X-Validakey-User-Id`, `X-Validakey-Host`). The sealed routes send a single encrypted field and nothing else.

## Development

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
