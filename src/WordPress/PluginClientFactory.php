<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Http\HttpTransport;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;
use Validakey\ValidakeyClient;
use Validakey\ValidakeyConfig;

/**
 * Builds a {@see ValidakeyClient} wired for WordPress plugins.
 *
 * Configuration is independent PHP constants (namespaced or global). This
 * factory assembles them into a config array / {@see ValidakeyConfig} and
 * persists the instance id with {@see OptionsInstanceStore} and, via
 * {@see license()}, the granted vKey with {@see OptionsTokenStore}.
 *
 * Requires WordPress only because of the options-backed store.
 */
final class PluginClientFactory
{
    private const CONSTANT_MAP = array(
        'base_url' => 'VALIDAKEY_BASE_URL',
        'api_uuid' => 'VALIDAKEY_API_UUID',
        'user_app_id' => 'VALIDAKEY_USER_APP_ID',
        'api_pkey' => 'VALIDAKEY_API_PKEY',
        'subject' => 'VALIDAKEY_SUBJECT',
        'host' => 'VALIDAKEY_HOST',
        'timeout' => 'VALIDAKEY_TIMEOUT',
    );

    /**
     * Assemble a config array from Validakey constants.
     *
     * Prefers namespaced constants when a namespace is available, then falls
     * back to global `define()` constants of the same short name.
     * `VALIDAKEY_API_PKEY` is optional and should not be shipped in distributed
     * plugins — define it only in a private deploy config when needed.
     *
     * @param string|null $namespace Plugin namespace that declares the constants.
     *                               When null, resolved by {@see callerNamespace_Guess()}.
     *                               Pass '' to read only global defines.
     *
     * @return array<string, mixed>
     */
    public static function configArrayFromConstants(?string $namespace = null): array
    {
        $namespace = self::resolveConstantsNamespace($namespace);
        $config = array();

        foreach (self::CONSTANT_MAP as $key => $shortName) {
            $value = self::constantValue($shortName, $namespace);
            if (null === $value) {
                continue;
            }

            if ('timeout' === $key) {
                $config[$key] = (float) $value;
                continue;
            }

            $string = trim((string) $value);
            if ('' === $string) {
                continue;
            }

            $config[$key] = $string;
        }

        // Legacy global name from before the private-key rename.
        if (! isset($config['api_pkey'])) {
            $legacy = self::constantValue('VALIDAKEY_API_SECRET', $namespace);
            if (null !== $legacy && '' !== trim((string) $legacy)) {
                $config['api_pkey'] = trim((string) $legacy);
            }
        }

        return $config;
    }

    /**
     * Whether the required license-path credentials are present.
     *
     * @param array<string, mixed>|null $config When null, loaded via
     *                                          {@see configArrayFromConstants()}.
     * @param string|null               $namespace Passed through when $config is null.
     */
    public static function isConfigured(?array $config = null, ?string $namespace = null): bool
    {
        return array() === self::missingRequiredConstants($config, $namespace);
    }

    /**
     * Required constants that are missing or empty (fully qualified when namespaced).
     *
     * @param array<string, mixed>|null $config
     *
     * @return list<string>
     */
    public static function missingRequiredConstants(?array $config = null, ?string $namespace = null): array
    {
        if (null === $config) {
            $namespace = self::resolveConstantsNamespace($namespace);
            $config = self::configArrayFromConstants($namespace ?? '');
        } elseif (null !== $namespace) {
            $namespace = self::resolveConstantsNamespace($namespace);
        }

        $missing = array();
        foreach (array('base_url' => 'VALIDAKEY_BASE_URL', 'api_uuid' => 'VALIDAKEY_API_UUID', 'user_app_id' => 'VALIDAKEY_USER_APP_ID') as $key => $shortName) {
            if (empty($config[$key])) {
                $missing[] = self::describeConstant($shortName, $namespace);
            }
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $config Keys from {@see configArrayFromConstants()}.
     */
    public static function configFromArray(array $config, ?string $namespace = null): ValidakeyConfig
    {
        $missing = self::missingRequiredConstants($config, $namespace);
        if (array() !== $missing) {
            throw new \InvalidArgumentException(
                'Validakey is not configured; missing or empty: ' . implode(', ', $missing) . '.'
            );
        }

        $apiPKey = self::stringOrNull($config['api_pkey'] ?? null);
        $subject = self::stringOrNull($config['subject'] ?? null);
        $host = self::stringOrNull($config['host'] ?? null);
        $timeout = isset($config['timeout']) ? (float) $config['timeout'] : 30.0;

        return new ValidakeyConfig(
            baseUrl: (string) $config['base_url'],
            apiUUID: (string) $config['api_uuid'],
            userAppId: (string) $config['user_app_id'],
            apiPKey: $apiPKey,
            subject: $subject,
            host: $host,
            timeout: $timeout,
        );
    }

    /**
     * Guess the WordPress plugin folder name for the calling plugin.
     *
     * Walks the call stack (or inspects $fromFile) and returns the directory
     * name immediately under the WordPress plugins directory.
     *
     * @param string|null $fromFile Absolute path to treat as the caller; when
     *                              null, uses {@see debug_backtrace()}.
     *
     * @throws \RuntimeException When no path under the plugins directory is found.
     */
    public static function pluginSlug_Guess(?string $fromFile = null): string
    {
        if (null !== $fromFile && '' !== trim($fromFile)) {
            $slug = self::slugFromPluginsPath($fromFile);
            if (null !== $slug) {
                return $slug;
            }

            throw new \RuntimeException(
                'Cannot guess plugin slug from path (not under the WordPress plugins directory): ' . $fromFile
            );
        }

        foreach (self::callerFrames() as $frame) {
            if (! isset($frame['file']) || ! is_string($frame['file'])) {
                continue;
            }

            $slug = self::slugFromPluginsPath($frame['file']);
            if (null !== $slug) {
                return $slug;
            }
        }

        throw new \RuntimeException(
            'Cannot guess plugin slug; pass $pluginSlug explicitly to PluginClientFactory::make().'
        );
    }

    /**
     * Guess the PHP namespace of the calling plugin code.
     *
     * Uses the first call-stack frame outside this library (class namespace, or
     * a `namespace …;` declaration in the caller file). Returns null when the
     * caller lives in the global namespace.
     */
    public static function callerNamespace_Guess(): ?string
    {
        foreach (self::callerFrames() as $frame) {
            if (isset($frame['class']) && is_string($frame['class'])) {
                $class = $frame['class'];
                if (str_starts_with($class, 'Validakey\\WordPress\\')) {
                    continue;
                }

                try {
                    $ns = (new \ReflectionClass($class))->getNamespaceName();
                } catch (\ReflectionException) {
                    continue;
                }

                return '' === $ns ? null : $ns;
            }

            if (! isset($frame['file']) || ! is_string($frame['file'])) {
                continue;
            }

            $ns = self::namespaceFromFile($frame['file']);
            if (null !== $ns) {
                return '' === $ns ? null : $ns;
            }
        }

        return null;
    }

    /**
     * WordPress option key used for instance ids for a plugin slug.
     */
    public static function optionNameForSlug(string $pluginSlug): string
    {
        $pluginSlug = trim($pluginSlug);
        if ('' === $pluginSlug) {
            throw new \InvalidArgumentException('pluginSlug must not be empty.');
        }

        return $pluginSlug . '_validakey_instances';
    }

    /**
     * WordPress option key used for granted vKeys for a plugin slug.
     */
    public static function tokenOptionNameForSlug(string $pluginSlug): string
    {
        $pluginSlug = trim($pluginSlug);
        if ('' === $pluginSlug) {
            throw new \InvalidArgumentException('pluginSlug must not be empty.');
        }

        return $pluginSlug . '_validakey_tokens';
    }

    /**
     * WordPress option key used for license verification snapshots for a plugin slug.
     */
    public static function checkOptionNameForSlug(string $pluginSlug): string
    {
        $pluginSlug = trim($pluginSlug);
        if ('' === $pluginSlug) {
            throw new \InvalidArgumentException('pluginSlug must not be empty.');
        }

        return $pluginSlug . '_validakey_license_checks';
    }

    /**
     * @param array<string, mixed>|null $config When null, loaded via
     *                                          {@see configArrayFromConstants()}.
     * @param string|null               $pluginSlug Plugin folder under wp-content/plugins.
     *                                              When null, {@see pluginSlug_Guess()}.
     * @param string|null               $constantsNamespace Namespace for constants when
     *                                                      $config is null. When null,
     *                                                      {@see callerNamespace_Guess()}.
     *                                                      Pass '' for global defines only.
     * @param string|null               $optionName Override `{slug}_validakey_instances`.
     */
    public static function make(
        ?array $config = null,
        ?string $pluginSlug = null,
        ?HttpTransport $transport = null,
        ?string $constantsNamespace = null,
        ?string $optionName = null,
    ): ValidakeyClient {
        if (null === $config) {
            $constantsNamespace = self::resolveConstantsNamespace($constantsNamespace);
            $config = self::configArrayFromConstants($constantsNamespace ?? '');
        }

        $pluginSlug = null !== $pluginSlug && '' !== trim($pluginSlug)
            ? trim($pluginSlug)
            : self::pluginSlug_Guess();

        $optionName = null !== $optionName && '' !== trim($optionName)
            ? trim($optionName)
            : self::optionNameForSlug($pluginSlug);

        return new ValidakeyClient(
            self::configFromArray($config, $constantsNamespace),
            $transport,
            new OptionsInstanceStore($optionName),
        );
    }

    /**
     * A {@see License} wired for this plugin: instance store, vKey store,
     * verification snapshot store, and a default token spec (free / type 1
     * when omitted).
     *
     * When `VALIDAKEY_SUBJECT` is unset and WordPress `home_url()` is
     * available, the client is scoped to the site host so the Instance Entity
     * is the site rather than a random single-tenant Subject ID.
     *
     * @param array<string, mixed>|null $config
     */
    public static function license(
        ?CreateTokenRequest $spec = null,
        ?array $config = null,
        ?string $pluginSlug = null,
        ?HttpTransport $transport = null,
        ?string $constantsNamespace = null,
        ?string $optionName = null,
        ?string $tokenOptionName = null,
        ?string $checkOptionName = null,
        int $revalidateInterval = License::DEFAULT_REVALIDATE_INTERVAL,
        bool $failClosed = false,
    ): License {
        $client = self::make($config, $pluginSlug, $transport, $constantsNamespace, $optionName);

        if (! $client->hasExplicitSubject()) {
            $siteSubject = self::wordpressSiteSubject();
            if (null !== $siteSubject) {
                $client = $client->forSubject($siteSubject);
            }
        }

        if (null === $pluginSlug || '' === trim($pluginSlug)) {
            $pluginSlug = self::pluginSlug_Guess();
        } else {
            $pluginSlug = trim($pluginSlug);
        }

        $tokenOptionName = null !== $tokenOptionName && '' !== trim($tokenOptionName)
            ? trim($tokenOptionName)
            : self::tokenOptionNameForSlug($pluginSlug);

        $checkOptionName = null !== $checkOptionName && '' !== trim($checkOptionName)
            ? trim($checkOptionName)
            : self::checkOptionNameForSlug($pluginSlug);

        return new License(
            $client,
            new OptionsTokenStore($tokenOptionName),
            $spec ?? CreateTokenRequest::free(),
            new OptionsLicenseCheckStore($checkOptionName),
            $revalidateInterval,
            $failClosed,
        );
    }

    /**
     * Site host from WordPress, used as the default IE subject.
     */
    private static function wordpressSiteSubject(): ?string
    {
        if (! \function_exists('home_url')) {
            return null;
        }

        $home = (string) home_url();
        if ('' === $home) {
            return null;
        }

        $host = \function_exists('wp_parse_url')
            ? wp_parse_url($home, PHP_URL_HOST)
            : parse_url($home, PHP_URL_HOST);

        if (! is_string($host) || '' === trim($host)) {
            return null;
        }

        return trim($host);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function callerFrames(): array
    {
        $librarySrc = self::normalizePath(\dirname(__DIR__));
        $frames = array();

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (isset($frame['class']) && is_string($frame['class'])) {
                if (str_starts_with($frame['class'], 'Validakey\\WordPress\\')) {
                    continue;
                }
                if (str_starts_with($frame['class'], 'PHPUnit\\')) {
                    continue;
                }
            } elseif (isset($frame['file']) && is_string($frame['file'])) {
                $file = self::normalizePath($frame['file']);
                if (str_starts_with($file, $librarySrc . '/')) {
                    continue;
                }
                $frame['file'] = $file;
            }

            if (isset($frame['file']) && is_string($frame['file'])) {
                $frame['file'] = self::normalizePath($frame['file']);
            }

            $frames[] = $frame;
        }

        return $frames;
    }

    /**
     * null → guess; '' → global only; non-empty → that namespace.
     */
    private static function resolveConstantsNamespace(?string $namespace): ?string
    {
        if (null === $namespace) {
            return self::callerNamespace_Guess();
        }

        $namespace = trim($namespace);

        return '' === $namespace ? null : $namespace;
    }

    private static function namespaceFromFile(string $file): ?string
    {
        $code = @file_get_contents($file);
        if (false === $code) {
            return null;
        }

        if (1 === preg_match('/namespace\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff\\\\]*)\s*;/', $code, $matches)) {
            return $matches[1];
        }

        return '';
    }

    private static function describeConstant(string $shortName, ?string $namespace): string
    {
        if (null !== $namespace && '' !== $namespace) {
            return $namespace . '\\' . $shortName;
        }

        return $shortName;
    }

    private static function constantValue(string $shortName, ?string $namespace): mixed
    {
        if (null !== $namespace && '' !== $namespace) {
            $fqn = $namespace . '\\' . $shortName;
            if (\defined($fqn)) {
                return \constant($fqn);
            }
        }

        if (\defined($shortName)) {
            return \constant($shortName);
        }

        return null;
    }

    /**
     * @return string|null Plugin folder name, or null when $path is not under plugins.
     */
    private static function slugFromPluginsPath(string $path): ?string
    {
        $path = self::normalizePath($path);
        $pluginsDir = self::pluginsDirectory();

        if (null !== $pluginsDir) {
            $prefix = rtrim($pluginsDir, '/') . '/';
            if (! str_starts_with($path, $prefix)) {
                return null;
            }

            $relative = substr($path, strlen($prefix));
            $slug = explode('/', $relative, 2)[0];

            return self::validSlug($slug);
        }

        if (1 !== preg_match('#/plugins/([^/]+)/#', $path, $matches)) {
            return null;
        }

        return self::validSlug($matches[1]);
    }

    private static function pluginsDirectory(): ?string
    {
        if (defined('WP_PLUGIN_DIR') && is_string(WP_PLUGIN_DIR) && '' !== WP_PLUGIN_DIR) {
            return self::normalizePath(WP_PLUGIN_DIR);
        }

        return null;
    }

    private static function validSlug(string $slug): ?string
    {
        $slug = trim($slug);
        if ('' === $slug || '.' === $slug || '..' === $slug) {
            return null;
        }

        return $slug;
    }

    private static function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
