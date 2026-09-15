# Validakey PHP Client — Documentation

This folder contains usage documentation for the `validakey/validakey-php` Composer package.

## Guides

| Document | Description |
|----------|-------------|
| [Getting started](getting-started.md) | Install the package and make your first API call |
| [Configuration](configuration.md) | Credentials, environment variables, and HTTP options |
| [Access and authentication](access-and-auth.md) | The instance handshake, the wire format, and what it protects |
| [API reference](api-reference.md) | Endpoints, request/response types, and client methods |
| [Error handling](error-handling.md) | Exceptions, HTTP status codes, and recovery patterns |
| [WordPress plugin integration](wordpress-plugin-integration.md) | Using the library from a WordPress plugin (Roundpeg example) |

## Quick example

```php
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;
use Validakey\Request\CreateTokenRequest;

$config = new ValidakeyConfig(
    baseUrl: 'https://example.validakey.host/v1',
    apiUUID: 'your-account-uuid-here',
    userAppId: 'your-user-app-id-here',
);

$client = new ValidakeyClient($config);
$token  = $client->createToken(new CreateTokenRequest(duration: 3600, basisCents: 999));

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

- Package source: public GitHub repository for `validakey/validakey-php`
- Server API: Validakey v1 (`/v1/…` routes). The account UUID is never in the URL: the token path encrypts it, the account path sends it as request context.
