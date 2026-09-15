# WordPress plugin integration

This guide explains how to use `validakey/validakey-php` from a WordPress plugin. The core client has no WordPress dependency. Optional helpers under `Validakey\WordPress\` make the usual plugin wiring just a few lines of code.

## Install in your plugin

Navigate to your plugin folder and run:

```bash
composer require validakey/validakey-php
```

## Add your Validakey credentials

On your Validakey.com web dashboard, create an application (APP ID) for this application. 

Some API credentials **must ship with the plugin**. Every install needs them to complete the instance handshake and collect IE license payments. Put them in a committed file such as `includes/defines.php` as **independent namespaced constants**:

```php
namespace YourPlugin;

/*
 * Some IDs are required to ship with your plugin code.
 *
 * NEVER define VALIDAKEY_API_PKEY in shipped code. That key is only for automating your
 *  Validakey account within your own secure application. Token minting never needs it.
 */
const VALIDAKEY_BASE_URL    = 'https://api.validakey.com/v1';
const VALIDAKEY_API_UUID    = 'your-account-uuid-here';
const VALIDAKEY_USER_APP_ID = 'your-user-app-id-here';
const VALIDAKEY_TIMEOUT     = 30.0;
// const VALIDAKEY_HOST    = 'www.example.com'; // optional; auto-detected when omitted
```



## Quick-start within a WordPress plugin

The shortest path: ship the three constants above, then register once at boot.

```php
namespace YourPlugin;

require plugin_dir_path(__FILE__) . 'vendor/autoload.php';
require plugin_dir_path(__FILE__) . 'includes/defines.php';

use Validakey\WordPress\LicenseBootstrap;

LicenseBootstrap::register(array(
    'plugin_slug' => 'your-plugin',     // optional; /plugins/{plugin_slug} when omitted
    'constants_namespace' => __NAMESPACE__,
    'settings_page' => 'your-plugin',       // $_GET['page'] for handleRequest scoping
    'redirect_url' => admin_url('options-general.php?page=your-plugin&tab=license'),
    // 'add_submenu' => true,               // optional dedicated License submenu
));

// Gate paid features on the last verification (no network call):
if (! LicenseBootstrap::allows()) {
    return;
}

register_deactivation_hook(__FILE__, static function () {
    LicenseBootstrap::deactivate('your-plugin');
});
```

On your License settings tab (or let `add_submenu` create one):

```php
LicenseBootstrap::renderPanel();
```

`register()` wires:

- `LicensePanel` POST handling on `admin_init` (Request button)
- WP-Cron revalidation (`{slug}_validakey_revalidate`, default `twicedaily`)
- Optional admin notice when the site is not licensed
- Stores: `{slug}_validakey_instances`, `{slug}_validakey_tokens`, `{slug}_validakey_license_checks`



### Gate vs live verify


| Call                                                | Network?                  | Use for                            |
| --------------------------------------------------- | ------------------------- | ---------------------------------- |
| `LicenseBootstrap::allows()` / `$license->allows()` | No                        | Feature gates on every request     |
| `$license->revalidate()`                            | Yes                       | Cron / forced refresh              |
| `$license->status()` / `isGranted()`                | Yes (if a vKey is stored) | Admin UI that needs a fresh answer |
| `$license->request()`                               | Yes when minting          | Admin Request button               |


`allows()` trusts the last snapshot. With default `fail_closed => false`, a granted check stays good until expiry or a failed revalidate. Set `fail_closed => true` (and optionally `revalidate_interval`) to deny when the snapshot is older than the interval.

### Manual factory (without bootstrap)

```php
use Validakey\WordPress\PluginClientFactory;

$license = PluginClientFactory::license();

if (! $license->allows()) {
    // show admin UI; do not mint on every front-end hit
}

$check = $license->status(); // live verify + refresh snapshot
```

`license()` loads your namespaced constants, stores the instance id under `{plugin-folder}_validakey_instances`, the granted vKey under `{plugin-folder}_validakey_tokens`, and verification snapshots under `{plugin-folder}_validakey_license_checks`. The handshake requires a non-empty subject. When `VALIDAKEY_SUBJECT` is unset, `license()` uses the site host from `home_url()` when available; otherwise the client assigns a random Subject ID and persists it with the instance id. The default mint is `CreateTokenRequest::free()` (type 1: no expiry, no price). If a required constant is missing, it throws `\InvalidArgumentException` naming what is still empty.

To work with the protocol client directly:

```php
use Validakey\WordPress\PluginClientFactory;
use Validakey\Request\CreateTokenRequest;

$validakeyClient = PluginClientFactory::make();

// Handshake requires a subject. make() does not apply the site-host default —
// if VALIDAKEY_SUBJECT is unset, the client assigns a random Subject ID.
// Handshake => '402' payment_required if no card on account yet
$instanceId = $validakeyClient->instanceId();

$token = $validakeyClient->createToken(new CreateTokenRequest(
    duration: 3600,
    basisCents: 999,
));
```



## Admin license panel

Prefer `LicenseBootstrap::register()` + `renderPanel()` above. For a custom tab without the bootstrap, call the panel helpers directly:

```php
use Validakey\WordPress\LicensePanel;
use Validakey\WordPress\PluginClientFactory;

add_action('admin_init', function () {
    if (! current_user_can('manage_options')) {
        return;
    }
    // Only on your settings page — check $_GET['page'] as needed.
    LicensePanel::handleRequest(
        PluginClientFactory::license(),
        array(
            'redirect_url' => admin_url('options-general.php?page=your-plugin&tab=license'),
        )
    );
});

// In your settings page callback:
$missing = PluginClientFactory::missingRequiredConstants();
LicensePanel::render(
    array() === $missing ? PluginClientFactory::license() : null,
    array(
        'configured' => array() === $missing,
        'redirect_url' => admin_url('options-general.php?page=your-plugin&tab=license'),
    )
);
```

`LicensePanel` is for **admin** screens (`manage_options` by default). It shows Subject, Status, Expires, Token Locator (`{IE prefix}_{vKey prefix}`), and a Request button when no grant exists. It is not a public shortcode.

## Notes for quick-start

When arguments to `make()` / `license()` are omitted:

- `constantsNamespace` defaults via `callerNamespace_Guess()` — the namespace of the calling class or file (not `Validakey\WordPress`).
- `pluginSlug` defaults via `pluginSlug_Guess()` — the folder name under `wp-content/plugins/`.

Pass either explicitly when the caller is not under your plugin (themes, mu-plugins, CLI scripts). Pass `constantsNamespace: ''` to read only global `define()` constants.

To degrade without throwing (for example during plugin bootstrap):

```php
$missing = PluginClientFactory::missingRequiredConstants();
if ($missing !== []) {
    error_log('Validakey not configured; missing: ' . implode(', ', $missing));
    return;
}
```

Optional `VALIDAKEY_API_PKEY` belongs only in a private config (for example `wp-config.php`) if you automate *your* account — never in a distributed plugin.

## Details on installing this library in your plugin

You can also add the library package to your plugin’s `composer.json` and then run `composer install`.

## API Credential Table


| Constant                | Ship in plugin? | Purpose                                                                                                                                                                                                                                        |
| ----------------------- | --------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `VALIDAKEY_BASE_URL`    | Yes             | API root                                                                                                                                                                                                                                       |
| `VALIDAKEY_API_UUID`    | Yes             | Your account UUID                                                                                                                                                                                                                              |
| `VALIDAKEY_USER_APP_ID` | Yes             | This application’s id (handshake key)                                                                                                                                                                                                          |
| `VALIDAKEY_HOST`        | Optional        | Pin hostname; otherwise auto-detected                                                                                                                                                                                                          |
| `VALIDAKEY_TIMEOUT`     | Optional        | HTTP timeout seconds (default 30)                                                                                                                                                                                                              |
| `VALIDAKEY_SUBJECT`     | Optional        | End-customer subject (required on handshake). When unset, `license()` uses the site host if `home_url()` exists; `make()` and the core client assign a random Subject ID instead. Empty subject is rejected by the server (`missing_subject`). |
| `VALIDAKEY_API_PKEY`    | **No**          | Account private key — omit for license plugins                                                                                                                                                                                                 |




### Persisting the instance id

Token calls are authenticated by an instance id from a handshake. Without somewhere to keep it, every PHP process handshakes again — and because a repeat handshake *rotates* the instance, concurrent requests would keep invalidating each other.

Do not use `FileInstanceStore` under `wp-content`: the instance id is a bearer secret and that path may be served over HTTP. Use the shipped options-backed store:

```php
use Validakey\WordPress\OptionsInstanceStore;
use Validakey\WordPress\PluginClientFactory;
use Validakey\ValidakeyClient;

$config = PluginClientFactory::configArrayFromConstants('YourPlugin');
$store  = new OptionsInstanceStore('yourplugin_validakey_instances');

$client = new ValidakeyClient(
    PluginClientFactory::configFromArray($config),
    null,
    $store,
);
```

Keys are already scoped by application id, subject, and machine fingerprint, so one store can serve several clients on the same site.

## Wrapper class pattern

For plugins that want a service object (lazy client, `is_configured()`, container registration), prefer registering `LicenseBootstrap` once and wrapping it:

```php
namespace YourPlugin;

use Validakey\License;
use Validakey\ValidakeyClient;
use Validakey\WordPress\LicenseBootstrap;

class YourPlugin_Validakey
{
    public function is_configured(): bool
    {
        return LicenseBootstrap::isConfigured();
    }

    public function allows(): bool
    {
        return LicenseBootstrap::allows();
    }

    public function license(): License
    {
        return LicenseBootstrap::license();
    }

    public function get_client(): ValidakeyClient
    {
        return $this->license()->client();
    }
}
```

Register during bootstrap (after `LicenseBootstrap::register()`):

```php
$this->set('YOUR_PLUGIN_VALIDAKEY', new YourPlugin_Validakey());
```



## Roundpeg example

The Roundpeg plugin includes a reference implementation:

- **Constants:** `includes/defines.php` (`VALIDAKEY_BASE_URL`, `VALIDAKEY_API_UUID`, `VALIDAKEY_USER_APP_ID`)
- **Boot:** `LicenseBootstrap::register()` from the core plugin class (License tab + cron + notices)
- **Wrapper:** `includes/class-roundpeg-validakey.php` — thin facade over `LicenseBootstrap::license()`
- **Admin:** Settings → RoundPeg Menus → License via `LicenseBootstrap::renderPanel()`
- **Stores:** `{slug}_validakey_instances`, `{slug}_validakey_tokens`, `{slug}_validakey_license_checks`
- **Container key:** `ROUNDPEG_VALIDAKEY`

Usage inside Roundpeg:

```php
use Validakey\WordPress\LicenseBootstrap;

if (LicenseBootstrap::allows()) {
    // paid features
}

// or via the container wrapper:
$validakey = Roundpeg::instance()->get_c()['ROUNDPEG_VALIDAKEY'];
if ($validakey->is_configured() && $validakey->license()->allows()) {
    // …
}
```



## When to call Validakey

Common integration points in WordPress plugins:


| Hook / context                    | Use case                                                                                  |
| --------------------------------- | ----------------------------------------------------------------------------------------- |
| Admin settings / License tab      | `LicenseBootstrap::renderPanel()`, or `LicensePanel` / `$license->status()` / `request()` |
| Any request (gate)                | `LicenseBootstrap::allows()` or `$license->allows()` (local snapshot)                     |
| WP-Cron (automatic via bootstrap) | `$license->revalidate()`                                                                  |
| Admin settings save               | Validate account billing via `getBillingStatus()` (needs `VALIDAKEY_API_PKEY`)            |
| REST API endpoint                 | Issue tokens for licensed features                                                        |
| Cron / Action Scheduler           | Renew or meter usage                                                                      |
| Activation hook                   | Verify billing is active before enabling paid features                                    |
| Site move / credential change     | Call `forgetInstance()` so the next call re-handshakes                                    |


Always check `is_configured()` before calling the API so the plugin degrades gracefully when constants are missing.

## Admin UI considerations

- Never expose `VALIDAKEY_API_PKEY`, `VALIDAKEY_USER_APP_ID`, the instance id, or the raw vKey in HTML or JavaScript.
- `VALIDAKEY_API_UUID` and `VALIDAKEY_USER_APP_ID` belong in server-side PHP that ships with the plugin — not in the browser.
- Use `getBillingConfig()` server-side to pass only Square **application ID** and **location ID** to the browser for card tokenization.
- Pass the Square `source_id` nonce from the browser to the server, then to `attachInstanceCard()` for a customer's card or `attachAccountCard()` for your own. Card numbers go from the browser to Square directly and must never reach PHP.
- Leave `VALIDAKEY_API_PKEY` unset unless the plugin genuinely manages *your* Validakey account - from a server that *you* fully control.



## Deployment checklist

- [ ] `composer install --no-dev` in the plugin directory on production
- [ ] `VALIDAKEY_BASE_URL`, `VALIDAKEY_API_UUID`, and `VALIDAKEY_USER_APP_ID` set in the shipped plugin constants
- [ ] `VALIDAKEY_API_PKEY` not present in the distributed package
- [ ] Plugin autoloads `vendor/autoload.php` before using Validakey classes
- [ ] `LicenseBootstrap::register()` (or equivalent) called at boot; `LicenseBootstrap::deactivate($slug)` on deactivation



## Further reading

- [Getting started](getting-started.md)
- [Configuration](configuration.md)
- [Access and authentication](access-and-auth.md)
- [API reference](api-reference.md)
- [Error handling](error-handling.md)

