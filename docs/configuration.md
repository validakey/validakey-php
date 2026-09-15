# Configuration

All credentials and environment-specific settings are supplied by your application. The library ships with no hardcoded secrets or production hostnames.

## ValidakeyConfig

```php
use Validakey\ValidakeyConfig;

$config = new ValidakeyConfig(
    baseUrl: 'https://example.validakey.host/v1',
    apiUUID: 'your-account-uuid-here',
    userAppId: 'your-user-app-id-here',
    apiPKey: 'your-private-key-here',  // optional; only for your own account calls
    subject: 'customer-42',            // optional; random Subject ID when omitted
    host: 'www.example.com',            // optional; auto-detected when omitted
    timeout: 30.0,                      // HTTP timeout in seconds
);
```

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `baseUrl` | `string` | Yes | Validakey API base URL. Trailing slashes are stripped automatically. Example: `https://example.validakey.host/v1` |
| `apiUUID` | `string` | Yes | Your Validakey account UUID. On the token path it is only ever sent encrypted. |
| `userAppId` | `string` | Yes | The application UUID issued by Validakey for this app. It keys the instance handshake, so treat it as a secret. |
| `apiPKey` | `?string` | No | Your account private key, for the account endpoints only. Omit it unless you are automating your own account; nothing to do with licenses needs it. |
| `subject` | `?string` | No | End-customer subject for multi-tenant applications. When omitted, the client assigns a random Subject ID and persists it in the instance store. |
| `host` | `?string` | No | Host name where this client runs. When omitted, resolved from `HTTP_HOST`, `SERVER_NAME`, or `gethostname()`. Forms part of the machine fingerprint. |
| `timeout` | `float` | No | Request timeout in seconds. Default: `30.0` |

Empty `baseUrl`, `apiUUID`, or `userAppId` throws `\InvalidArgumentException` at construction time.

`userAppId` must be at least 12 characters, because the key schedule reads a time offset from it. In practice it is always a UUIDv4 issued by the server.

## Instance store

Token requests are keyed by an instance id obtained through a handshake. The client needs somewhere to keep it:

```php
use Validakey\Instance\FileInstanceStore;
use Validakey\Instance\InMemoryInstanceStore;

// Default when you pass nothing: one handshake per PHP process.
$client = new ValidakeyClient($config);

// Persistent: handshake once, reuse until the server rejects it.
$client = new ValidakeyClient(
    $config,
    null,
    new FileInstanceStore('/var/lib/myapp/validakey-instance.json'),
);
```

`FileInstanceStore` creates its directory `0700` and the file `0600`, and replaces it atomically. Because a repeat handshake *rotates* the instance and invalidates the previous one, persistence is not just a performance concern — without it, two concurrent processes will keep invalidating each other.

Implement `Validakey\Instance\InstanceStore` for anything else. On WordPress, use the shipped options-backed store (autoload off) instead of a file under `wp-content`, which may be web-servable:

```php
use Validakey\WordPress\OptionsInstanceStore;

$store = new OptionsInstanceStore('myplugin_validakey_instances');
```

Or build the client in one step with `Validakey\WordPress\PluginClientFactory::make(constantsNamespace: 'YourPlugin')` after defining `VALIDAKEY_BASE_URL`, `VALIDAKEY_API_UUID`, and `VALIDAKEY_USER_APP_ID`. See [WordPress plugin integration](wordpress-plugin-integration.md).

Keys are already scoped by application id, subject, and fingerprint, so one store can safely serve several clients.

## Token store

The instance id authenticates later calls; the vKey is the license. `Validakey\License` persists it through `Validakey\Instance\TokenStore` (in-memory by default in tests; `OptionsTokenStore` on WordPress as `{pluginSlug}_validakey_tokens`) and the last verification through `LicenseCheckStore` (`OptionsLicenseCheckStore` as `{pluginSlug}_validakey_license_checks`). Prefer `LicenseBootstrap::register()` or `PluginClientFactory::license()` over wiring this yourself.

## Application context

The cleartext identity fields are attached **only** to Bearer-authenticated calls, where the caller has already proven who it is:

| Channel | Fields |
|---------|--------|
| HTTP headers | `X-Validakey-User-App-Id`, `X-Validakey-Host`, `X-Validakey-User-Id` |
| JSON body (POST) | `user_app_id`, `host`, `api_user_id` |
| Query string (GET) | `user_app_id`, `host`, `api_user_id` |

The token path (`/v1/i/`, `/v1/r/`, `/v1/m/`, `/v1/v/`, `/v1/p/`) sends none of these. It carries the same identity inside the encrypted envelope, which is the point of the handshake — see [Access and authentication](access-and-auth.md).

```php
$context = $client->applicationContext();
echo $context->userAppId;
echo $context->host;
echo $context->apiUUID;
```

**Note:** `user_app_id` is Validakey's application identifier. Square's `application_id`, returned by `getBillingConfig()`, is unrelated — it identifies your Square Web Payments SDK app for card collection.

## Environment variables

Load configuration from the environment with `ValidakeyConfig::fromEnv()`:

| Variable | Maps to |
|----------|---------|
| `VALIDAKEY_BASE_URL` | `baseUrl` |
| `VALIDAKEY_API_UUID` | `apiUUID` |
| `VALIDAKEY_USER_APP_ID` | `userAppId` |
| `VALIDAKEY_SUBJECT` | `subject` (optional) |
| `VALIDAKEY_HOST` | `host` (optional) |
| `VALIDAKEY_API_PKEY` | `apiPKey` (optional) |
| `VALIDAKEY_TIMEOUT` | `timeout` (optional) |

```php
$config = ValidakeyConfig::fromEnv();
```

Use a custom prefix if needed:

```php
$config = ValidakeyConfig::fromEnv('MYAPP_VALIDAKEY_');
// Reads MYAPP_VALIDAKEY_BASE_URL, etc.
```

For local development, copy `.env.example` to `.env` and load it with your preferred dotenv tool. **Do not commit `.env`.**

## URL structure

The client builds URLs as:

```
{baseUrl}/{action}/
{baseUrl}/billing/{subaction}/
```

Examples with `baseUrl = https://example.validakey.host/v1`:

- Instance handshake: `POST …/v1/i/` (body: `{"q": "<sealed>"}`)
- Token create: `POST …/v1/m/` (body: `{"q": "<sealed>"}`)
- Token validate: `POST …/v1/v/` (body: `{"q": "<sealed>"}`)
- Subject transfer: `POST …/v1/t/` (body: `{"q": "<sealed>"}`)
- Billing status: `GET …/v1/billing/status/?api_user_id=…`

No endpoint carries the account UUID in the URL path.

## Authentication by endpoint

| Endpoint group | Authentication |
|----------------|----------------|
| Instance handshake (`getInstanceToken`) | Sealed with `userAppId` |
| Instance rotation (`rotateInstance`) | Sealed with the instance id being replaced |
| Subject transfer (`transferSubject`) | Sealed with the current instance id |
| Token create (`createToken`) | Sealed with the instance id |
| Token validate, renew, revoke | Sealed with the instance id |
| Instance Entity card operations | Sealed with the instance id |
| User info, billing config and status, attach/detach your card | `Bearer apiPKey` |

Calling a Bearer-authenticated method without a private key configured throws `Validakey\Exception\ValidakeyException` before anything is sent.

## Clock accuracy

The key schedule quantizes time into 256-second buckets, and the server tries the current bucket plus one either side. A client clock off by more than roughly four minutes will fail to handshake, reported as:

> Could not decrypt the Validakey instance handshake reply. This usually means the client and server clocks differ by more than the key rotation window, or the credentials do not match.

Keep NTP running.

## Custom HTTP transport

By default the client uses Symfony HttpClient. Inject a custom transport for testing or middleware:

```php
use Validakey\Http\HttpTransport;
use Validakey\ValidakeyClient;

$client = new ValidakeyClient($config, $myTransport);
```

Your transport must implement `Validakey\Http\HttpTransport` and return Symfony `ResponseInterface` objects.

## WordPress / plugin configuration

For WordPress plugins that sell licenses, ship `VALIDAKEY_BASE_URL`, `VALIDAKEY_API_UUID`, and `VALIDAKEY_USER_APP_ID` as namespaced constants in the distributed package. Assemble them with `PluginClientFactory::configArrayFromConstants()`. Keep `VALIDAKEY_API_PKEY` out of the package unless you are automating your own account.

See [WordPress plugin integration](wordpress-plugin-integration.md).

## Security checklist

- Always use HTTPS. The envelope obfuscates identifiers; it is not a replacement for transport security.
- Ship `apiUUID` and `userAppId` with license-path software; that is required for the handshake. Do not put them in browser JavaScript.
- Never commit or distribute the account private key (`apiPKey` / `VALIDAKEY_API_PKEY`). Nothing on the license path needs it.
- Use environment variables or private deploy config only for the private key and non-distributed overrides.
- Rotate the private key if it is ever exposed; the dashboard can revoke and reissue it. Rotating a leaked `user_app_id` is a product release (new app id in the shipped plugin).
