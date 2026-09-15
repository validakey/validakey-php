# Getting started

This guide walks through installing the Validakey PHP client and issuing your first access token.

## Install

Add the package to your project:

```bash
composer require validakey/validakey-php
```

Ensure your application loads Composer’s autoloader:

```php
require __DIR__ . '/vendor/autoload.php';
```

WordPress plugins typically load autoload from the plugin root in the main plugin file or bootstrap class.

## Obtain credentials

You need these values from your Validakey account and deployment:

| Value | Description |
|-------|-------------|
| **Base URL** | API root, e.g. `https://your-validakey-host/v1` |
| **Account UUID** | Your UUIDv4 account identifier |
| **App ID** | The application UUID Validakey issued for this integration. Not Square’s application ID. |
| **Subject ID** | End-customer id for multi-tenant apps. |
| **Host** | Optional. The hostname where this client runs; auto-detected when omitted |
| **Private key** | Bearer token for your own account endpoints. Not needed for anything to do with licenses. |

Store these in environment variables or your application’s private configuration — not in the library and not in a public repository. The user app ID is a credential, not a label: it is the key to the handshake, so anyone holding it can mint tokens against your account.

The private key is the one credential you should usually leave out entirely. It can change your billing details and read your account's usage, and no license operation needs it, so software you distribute should not carry it.

## Configure the client

```php
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;

$config = ValidakeyConfig::fromEnv();

// Or pass values explicitly:
$config = new ValidakeyConfig(
    baseUrl: getenv('VALIDAKEY_BASE_URL'),
    apiUUID: getenv('VALIDAKEY_API_UUID'),
    userAppId: getenv('VALIDAKEY_USER_APP_ID'),
    apiPKey: getenv('VALIDAKEY_API_PKEY'),
    subject: getenv('VALIDAKEY_SUBJECT') ?: null,
    host: getenv('VALIDAKEY_HOST') ?: null,
);

$client = new ValidakeyClient($config);
```

See [Configuration](configuration.md) for all options.

## Give the client somewhere to keep its instance

Token calls are authenticated by an instance id the client obtains through a handshake. That instance is scoped by app, subject, and machine fingerprint. By default it lives in memory, which means one handshake per PHP process — fine for a script, wasteful for a web app, and actively harmful under concurrency, since each handshake invalidates the last.

For anything long-lived, persist it:

```php
use Validakey\Instance\FileInstanceStore;

$client = new ValidakeyClient(
    $config,
    null,
    new FileInstanceStore('/var/lib/myapp/validakey-instance.json'),
);
```

Choose a path outside your web root. On WordPress, use the options table instead — see [WordPress plugin integration](wordpress-plugin-integration.md#persisting-the-instance-id).

## Create a token

Token creation does not require the private key. It does require the Validakey account to have a card on file with its monthly dues current, and — where the vKey carries a price — the customer running this instance to have a card of their own. The first call handshakes automatically; later calls reuse the stored instance.

```php
use Validakey\Request\CreateTokenRequest;
use Validakey\Exception\PaymentRequiredException;

try {
    $response = $client->createToken(new CreateTokenRequest(
        duration: 3600,
        basisCents: 999,
    ));

    echo $response->token;      // Plaintext token — store securely on your side
    echo $response->expiresAt;  // Unix timestamp
} catch (PaymentRequiredException $e) {
    // 'ie_card_required' means the customer needs to enter a card:
    // send them to requestInstancePaymentLink() or attachInstanceCard().
    // Anything else means your own account's billing needs attention.
    echo $e->errorCode;
}
```

## Validate a token

The customer's machine checks its own license over the same sealed channel, with no account credential involved:

```php
$check = $client->verifyToken($response->token);

if (! $check->isValid()) {
    exit($check->reason());
}
```

## Check billing status

Your own account endpoints require the private key:

```php
$status = $client->getBillingStatus();

if ($status->hasCard()) {
    echo $status->billingStatus(); // e.g. "active"
}
```

## Typical integration flow

1. Register a Validakey account and note your account UUID.
2. Register an application to get a user app ID.
3. Attach your own payment card on the Validakey dashboard, which also enrols you in the monthly service fee. The first period is granted, not charged.
4. Configure the PHP client with base URL, account UUID, user app ID, and an instance store. Leave the private key out unless you are automating your own account.
5. Call `createToken()` when you need to issue a vKey; the handshake happens on the first call.
6. If your vKeys carry a price, collect the customer's card with `attachInstanceCard()` or `requestInstancePaymentLink()` before minting.
7. Call `verifyToken()` where the license is checked, and `renewToken()` or `deleteToken()` as its lifecycle demands.

## Next steps

- [Configuration](configuration.md) — timeouts, env vars, instance stores, custom HTTP transport
- [Access and authentication](access-and-auth.md) — what the handshake does and why
- [API reference](api-reference.md) — full method and endpoint list
- [Error handling](error-handling.md) — exception types and common failures
- [WordPress plugin integration](wordpress-plugin-integration.md) — `OptionsInstanceStore` and `PluginClientFactory`
