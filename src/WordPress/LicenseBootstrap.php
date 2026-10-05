<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Exception\ValidakeyException;
use Validakey\License;
use Validakey\Request\CreateTokenRequest;

/**
 * One-call WordPress wiring for license setup, admin panel, cron, and gating.
 *
 * Multiple host plugins may register concurrently (keyed by plugin_slug).
 *
 * Typical plugin boot:
 *
 *     LicenseBootstrap::register([
 *         'plugin_slug' => 'myplugin',
 *         'settings_page' => 'myplugin-settings',
 *         'redirect_url' => admin_url('options-general.php?page=myplugin-settings&tab=license'),
 *     ]);
 *
 * Then gate features with {@see allows()} and render the panel with
 * {@see renderPanel()} on your License tab (or set `add_submenu` => true).
 *
 * When `plugin_slug` is omitted, {@see PluginClientFactory::pluginSlug_Guess()}
 * resolves it from the calling plugin path under `wp-content/plugins/`.
 *
 * @phpstan-type BootstrapOptions array{
 *     plugin_slug?: string|null,
 *     constants_namespace?: string|null,
 *     settings_page?: string|null,
 *     redirect_url?: string,
 *     add_submenu?: bool,
 *     parent_slug?: string,
 *     menu_title?: string,
 *     page_title?: string,
 *     menu_slug?: string|null,
 *     capability?: string,
 *     schedule?: bool,
 *     recurrence?: string,
 *     revalidate_interval?: int,
 *     fail_closed?: bool,
 *     admin_notice?: bool,
 *     admin_notice_message?: string,
 *     token_spec?: CreateTokenRequest|null,
 *     action?: string,
 *     delete_action?: string,
 *     nonce_field?: string,
 *     error_transient?: string,
 *     settings_group?: string,
 *     heading?: string|null,
 *     wrapper_class?: string,
 *     unconfigured_message?: string
 * }
 */
final class LicenseBootstrap
{
    /** @var array<string, self> */
    private static array $instances = array();

    /** Last registered instance (back-compat for callers that omit plugin_slug). */
    private static ?self $instance = null;

    private ?License $license = null;

    private bool $configured = false;

    /** @var list<string> */
    private array $missing = array();

    /**
     * @var array{
     *     plugin_slug: string,
     *     constants_namespace: string|null,
     *     settings_page: string|null,
     *     redirect_url: string,
     *     add_submenu: bool,
     *     parent_slug: string,
     *     menu_title: string,
     *     page_title: string,
     *     menu_slug: string,
     *     capability: string,
     *     schedule: bool,
     *     recurrence: string,
     *     revalidate_interval: int,
     *     fail_closed: bool,
     *     admin_notice: bool,
     *     admin_notice_message: string,
     *     token_spec: CreateTokenRequest|null,
     *     action: string,
     *     delete_action: string,
     *     nonce_field: string,
     *     error_transient: string,
     *     settings_group: string,
     *     heading: string|null,
     *     wrapper_class: string,
     *     unconfigured_message: string
     * }
     */
    private array $options;

    /**
     * Register (or replace) bootstrap wiring for one plugin slug.
     *
     * Multiple plugins may call register(); each keeps its own admin_init
     * handlers. Passing the same plugin_slug again replaces that plugin only.
     *
     * @param BootstrapOptions $options
     */
    public static function register(array $options = array()): self
    {
        $boot = new self($options);
        $slug = $boot->options['plugin_slug'];

        if (isset(self::$instances[$slug])) {
            self::unregister($slug);
        }

        self::$instances[$slug] = $boot;
        self::$instance = $boot;
        $boot->boot();

        return $boot;
    }

    /**
     * Unregister one plugin slug, or every instance when $pluginSlug is null.
     */
    public static function unregister(?string $pluginSlug = null): void
    {
        if (null === $pluginSlug) {
            foreach (array_keys(self::$instances) as $slug) {
                self::unregister($slug);
            }

            return;
        }

        if (! isset(self::$instances[$pluginSlug])) {
            return;
        }

        $boot = self::$instances[$pluginSlug];
        $hook = LicenseScheduler::hookForSlug($boot->options['plugin_slug']);
        LicenseScheduler::unschedule($hook);

        if (\function_exists('remove_action')) {
            \remove_action('admin_init', array($boot, 'onAdminInit'));
            \remove_action('admin_menu', array($boot, 'onAdminMenu'));
            \remove_action('admin_notices', array($boot, 'onAdminNotices'));
        }

        unset(self::$instances[$pluginSlug]);
        if (self::$instance === $boot) {
            self::$instance = array() === self::$instances
                ? null
                : self::$instances[array_key_last(self::$instances)];
        }
    }

    public static function instance(?string $pluginSlug = null): ?self
    {
        if (null !== $pluginSlug && '' !== trim($pluginSlug)) {
            return self::$instances[trim($pluginSlug)] ?? null;
        }

        return self::$instance;
    }

    /**
     * Whether license-path constants were present at register time.
     */
    public static function isConfigured(?string $pluginSlug = null): bool
    {
        $boot = self::instance($pluginSlug);

        return null !== $boot && $boot->configured;
    }

    /**
     * Shared license helper. Throws when bootstrap was never registered or
     * credentials are missing.
     */
    public static function license(?string $pluginSlug = null): License
    {
        $boot = self::instance($pluginSlug);
        if (null === $boot) {
            throw new \RuntimeException('Validakey LicenseBootstrap::register() has not been called.');
        }

        return $boot->resolveLicense();
    }

    /**
     * Gate on the last verification (no network). False when unconfigured.
     */
    public static function allows(?string $pluginSlug = null): bool
    {
        $boot = self::instance($pluginSlug);
        if (null === $boot || ! $boot->configured) {
            return false;
        }

        try {
            return $boot->resolveLicense()->allows();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Render the admin License panel (status + Request).
     *
     * @param array<string, mixed> $panelOptions Merged over register() panel keys
     */
    public static function renderPanel(array $panelOptions = array(), ?string $pluginSlug = null): string
    {
        $boot = self::instance($pluginSlug);
        if (null === $boot) {
            throw new \RuntimeException('Validakey LicenseBootstrap::register() has not been called.');
        }

        return $boot->render($panelOptions);
    }

    /**
     * Call from your plugin deactivation hook to clear the cron event.
     */
    public static function deactivate(string $pluginSlug): void
    {
        LicenseScheduler::unschedule(LicenseScheduler::hookForSlug($pluginSlug));
    }

    /**
     * @param BootstrapOptions $options
     */
    private function __construct(array $options)
    {
        $this->options = $this->normalize($options);
        $this->missing = PluginClientFactory::missingRequiredConstants(
            null,
            $this->options['constants_namespace']
        );
        $this->configured = array() === $this->missing;
    }

    private function boot(): void
    {
        if (\function_exists('add_action')) {
            \add_action('admin_init', array($this, 'onAdminInit'));
            if ($this->options['add_submenu']) {
                \add_action('admin_menu', array($this, 'onAdminMenu'));
            }
            if ($this->options['admin_notice']) {
                \add_action('admin_notices', array($this, 'onAdminNotices'));
            }
        }

        if ($this->options['schedule'] && $this->configured) {
            $hook = LicenseScheduler::hookForSlug($this->options['plugin_slug']);
            LicenseScheduler::schedule(
                $hook,
                array($this, 'onRevalidate'),
                $this->options['recurrence']
            );
        }
    }

    public function onAdminInit(): void
    {
        if (! \is_admin() || ! \current_user_can($this->options['capability'])) {
            return;
        }

        $page = isset($_GET['page']) ? \sanitize_key(\wp_unslash((string) $_GET['page'])) : '';
        $expected = $this->options['settings_page'] ?? $this->options['menu_slug'];
        if (null !== $expected && '' !== $expected && $page !== $expected) {
            return;
        }

        if ('' === $this->options['redirect_url']) {
            return;
        }

        LicensePanel::handleRequest(
            $this->configured ? $this->resolveLicense() : null,
            $this->panelOptions()
        );
        LicensePanel::handleDelete(
            $this->configured ? $this->resolveLicense() : null,
            $this->panelOptions()
        );
    }

    public function onAdminMenu(): void
    {
        if (! \function_exists('add_submenu_page')) {
            return;
        }

        \add_submenu_page(
            $this->options['parent_slug'],
            $this->options['page_title'],
            $this->options['menu_title'],
            $this->options['capability'],
            $this->options['menu_slug'],
            array($this, 'renderSubmenuPage')
        );
    }

    public function renderSubmenuPage(): void
    {
        if (! \current_user_can($this->options['capability'])) {
            return;
        }

        echo '<div class="wrap">';
        echo '<h1>' . \esc_html($this->options['page_title']) . '</h1>';
        $this->render();
        echo '</div>';
    }

    public function onAdminNotices(): void
    {
        if (! \is_admin() || ! \current_user_can($this->options['capability'])) {
            return;
        }

        if (! $this->configured || self::allows()) {
            return;
        }

        echo '<div class="notice notice-warning"><p>'
            . \esc_html($this->options['admin_notice_message'])
            . '</p></div>';
    }

    public function onRevalidate(): void
    {
        if (! $this->configured) {
            return;
        }

        try {
            $this->resolveLicense()->revalidate();
        } catch (ValidakeyException) {
            // Leave the previous snapshot in place on transient API failure.
        }
    }

    /**
     * @param array<string, mixed> $panelOptions
     */
    private function render(array $panelOptions = array()): string
    {
        return LicensePanel::render(
            $this->configured ? $this->resolveLicense() : null,
            array_merge($this->panelOptions(), $panelOptions)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function panelOptions(): array
    {
        return array(
            'configured' => $this->configured,
            'redirect_url' => $this->options['redirect_url'],
            'capability' => $this->options['capability'],
            'action' => $this->options['action'],
            'delete_action' => $this->options['delete_action'],
            'nonce_field' => $this->options['nonce_field'],
            'error_transient' => $this->options['error_transient'],
            'settings_group' => $this->options['settings_group'],
            'heading' => $this->options['heading'],
            'wrapper_class' => $this->options['wrapper_class'],
            'unconfigured_message' => $this->options['unconfigured_message'],
        );
    }

    private function resolveLicense(): License
    {
        if (! $this->configured) {
            throw new \RuntimeException(
                'Validakey is not configured; missing: ' . implode(', ', $this->missing)
            );
        }

        if (null === $this->license) {
            $this->license = PluginClientFactory::license(
                $this->options['token_spec'],
                null,
                $this->options['plugin_slug'],
                null,
                $this->options['constants_namespace'],
                null,
                null,
                null,
                $this->options['revalidate_interval'],
                $this->options['fail_closed'],
            );
        }

        return $this->license;
    }

    /**
     * @param BootstrapOptions $options
     *
     * @return array{
     *     plugin_slug: string,
     *     constants_namespace: string|null,
     *     settings_page: string|null,
     *     redirect_url: string,
     *     add_submenu: bool,
     *     parent_slug: string,
     *     menu_title: string,
     *     page_title: string,
     *     menu_slug: string,
     *     capability: string,
     *     schedule: bool,
     *     recurrence: string,
     *     revalidate_interval: int,
     *     fail_closed: bool,
     *     admin_notice: bool,
     *     admin_notice_message: string,
     *     token_spec: CreateTokenRequest|null,
     *     action: string,
     *     nonce_field: string,
     *     error_transient: string,
     *     settings_group: string,
     *     heading: string|null,
     *     wrapper_class: string,
     *     unconfigured_message: string
     * }
     */
    private function normalize(array $options): array
    {
        $pluginSlug = isset($options['plugin_slug']) && '' !== trim((string) $options['plugin_slug'])
            ? trim((string) $options['plugin_slug'])
            : PluginClientFactory::pluginSlug_Guess();

        $menuSlug = isset($options['menu_slug']) && '' !== trim((string) $options['menu_slug'])
            ? trim((string) $options['menu_slug'])
            : $pluginSlug . '-license';

        $settingsPage = \array_key_exists('settings_page', $options)
            ? (null === $options['settings_page'] || '' === trim((string) $options['settings_page'])
                ? null
                : trim((string) $options['settings_page']))
            : $menuSlug;

        $action = isset($options['action']) && '' !== trim((string) $options['action'])
            ? trim((string) $options['action'])
            : LicensePanel::DEFAULT_ACTION;

        $redirect = isset($options['redirect_url']) ? (string) $options['redirect_url'] : '';
        if ('' === $redirect && \function_exists('admin_url')) {
            $redirect = \admin_url('admin.php?page=' . $menuSlug);
        }

        $ns = \array_key_exists('constants_namespace', $options)
            ? $options['constants_namespace']
            : null;

        return array(
            'plugin_slug' => $pluginSlug,
            'constants_namespace' => is_string($ns) || null === $ns ? $ns : null,
            'settings_page' => $settingsPage,
            'redirect_url' => $redirect,
            'add_submenu' => ! empty($options['add_submenu']),
            'parent_slug' => isset($options['parent_slug']) && '' !== trim((string) $options['parent_slug'])
                ? trim((string) $options['parent_slug'])
                : 'options-general.php',
            // Keep defaults as English here — do not call __() during register()
            // (often runs before init and triggers WP 6.7 JIT textdomain notices).
            // Translate at display time in onAdminMenu / onAdminNotices / panelOptions.
            'menu_title' => isset($options['menu_title']) && '' !== trim((string) $options['menu_title'])
                ? (string) $options['menu_title']
                : 'License',
            'page_title' => isset($options['page_title']) && '' !== trim((string) $options['page_title'])
                ? (string) $options['page_title']
                : 'License',
            'menu_slug' => $menuSlug,
            'capability' => isset($options['capability']) && '' !== trim((string) $options['capability'])
                ? trim((string) $options['capability'])
                : LicensePanel::DEFAULT_CAPABILITY,
            'schedule' => ! \array_key_exists('schedule', $options) || (bool) $options['schedule'],
            'recurrence' => isset($options['recurrence']) && '' !== trim((string) $options['recurrence'])
                ? trim((string) $options['recurrence'])
                : LicenseScheduler::DEFAULT_RECURRENCE,
            'revalidate_interval' => isset($options['revalidate_interval'])
                ? max(60, (int) $options['revalidate_interval'])
                : License::DEFAULT_REVALIDATE_INTERVAL,
            'fail_closed' => ! empty($options['fail_closed']),
            'admin_notice' => ! \array_key_exists('admin_notice', $options) || (bool) $options['admin_notice'],
            'admin_notice_message' => isset($options['admin_notice_message']) && '' !== trim((string) $options['admin_notice_message'])
                ? (string) $options['admin_notice_message']
                : 'This plugin requires a Validakey license. Open the License settings tab to request one.',
            'token_spec' => isset($options['token_spec']) && $options['token_spec'] instanceof CreateTokenRequest
                ? $options['token_spec']
                : null,
            'action' => $action,
            'delete_action' => isset($options['delete_action']) && '' !== trim((string) $options['delete_action'])
                ? trim((string) $options['delete_action'])
                : LicensePanel::DEFAULT_DELETE_ACTION,
            'nonce_field' => isset($options['nonce_field']) && '' !== trim((string) $options['nonce_field'])
                ? trim((string) $options['nonce_field'])
                : $action . '_nonce',
            'error_transient' => isset($options['error_transient']) && '' !== trim((string) $options['error_transient'])
                ? trim((string) $options['error_transient'])
                : $pluginSlug . '_validakey_license_last_error',
            'settings_group' => isset($options['settings_group']) && '' !== trim((string) $options['settings_group'])
                ? trim((string) $options['settings_group'])
                : $pluginSlug . '_validakey_license_messages',
            'heading' => \array_key_exists('heading', $options)
                ? (null === $options['heading'] ? null : (string) $options['heading'])
                : 'License',
            'wrapper_class' => isset($options['wrapper_class']) && '' !== trim((string) $options['wrapper_class'])
                ? trim((string) $options['wrapper_class'])
                : 'validakey-license-panel',
            'unconfigured_message' => isset($options['unconfigured_message']) && '' !== trim((string) $options['unconfigured_message'])
                ? (string) $options['unconfigured_message']
                : 'Validakey is not configured. License-path constants (VALIDAKEY_BASE_URL, VALIDAKEY_API_UUID, VALIDAKEY_USER_APP_ID) must ship with the plugin.',
        );
    }
}
