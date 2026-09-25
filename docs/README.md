# Validakey PHP Client — Documentation

This folder contains usage documentation for the `validakey/validakey-php` Composer package.

## Guides

| Document | Description |
|----------|-------------|
| [Getting started](getting-started.md) | Install the package and make your first API call |
|| [Configuration](configuration.md) | Credentials, environment variables, and HTTP options |
| [Access and authentication](access-and-auth.md) | The instance handshake, the wire format, and what it protects |
| [API reference](api-reference.md) | Endpoints, request/response types, and client methods |
| [Error handling](error-handling.md) | Exceptions, HTTP status codes, and recovery patterns |
| [WordPress plugin integration](wordpress-plugin-integration.md) | Using the library from a WordPress plugin (Roundpeg example)

## Quick example

```php
use Validakey\ValidakeyClient;
    20|use Validakey\ValidakeyConfig;
use Validakey\Request\CreateTokenRequest;

$config = new ValidakeyConfig(
    baseUrl: 'https://api.validakey.com/v1',
    apiUUID: 'your-account-uuid-here',
    userAppId: 'your-user-app-id-here',
);

$client = new ValidakeyClient($config);
    30|$token  = $client->createToken(new CreateTokenRequest(duration: 3600, basisCents: 999));

echo $token->token;

$client->verifyToken($token->token)->isValid();
```

No `apiPKey` in that example, on purpose. Minting, validating, renewing and revoking a vKey — and collecting your customer's card — are all authenticated by the instance handshake. The private key is only for reading and changing your own account, and software you distribute should not carry it.

The first `createToken()` call performs an instance handshake behind the scenes. For anything longer-lived than a single script, pass an instance store so it is not repeated — see [Configuration](configuration.md#instance-store).

Replace placeholder values with credentials from your Validakey account. Never commit credentials to source control. Note that `userAppId` is one too, not just a label.

## Requirements

- PHP 8.1 or later
- Composer
- Network access to your Validakey server

## Support
    50|
- Package source: public GitHub repository for `validakey/validakey-php`
- Server API: Validakey v1 (`/v1/…` routes). The account UUID is never in the URL: the token path encrypts it, the account path sends it as request context.

## Request types

### CreateTokenRequest

| Property | Type | Default | API field |
|----------|------|---------|-----------|
| `duration` | `int` | 3600 (seconds) | `duration` (seconds) |
| **expiresAt** | `?int` | null | `expires_at` (Unix timestamp) |
| **uses** | `?int` | null | `uses` (max use count) |
| **recurrence** | `?string` | null | `recurrence` (e.g., 'monthly', 'daily') |
| **autoRenew** | `?bool` | null | `auto_renew` |
| `basisCents` | `?int` | null | `basis_cents` |
| `amount` | `?float` | null | `amount` (USD dollars) |
| `costUsd` | `?float` | null | `cost_USD` |

Only one price field is sent; priority is `basisCents`, then `amount`, then `costUsd`.
`noExpiry` must be asked for explicitly. Reading a zero duration as "forever" would turn a misconfigured value into a perpetual license.

The documented vKey types are combinations of these fields:

| Type | Request |
|------|---|
| 1 Transactional | `CreateTokenRequest::free()` or `noExpiry: true` |
| 2 Limited | `duration` and/or `uses` (at least one) |
| 3 Subscription Time | Duration + autoRenew + recurrence |
| 4 Subscription Use | Uses + autoRenew + price |

## Response

When successful, returns a `TokenResponse` containing:
- `token`: The access token
- `created`: Unix timestamp of issuance
- `expiresAt`: Unix timestamp when license expires (null if no expiry)
- Full raw payload with additional details
