<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Request\AttachCardRequest;
use Validakey\ValidakeyClient;

/**
 * Admin settings panel for Instance Entity (customer) payment cards.
 *
 * Complements {@see InstanceCardForm}: status, detach, hosted payment link,
 * and the Square embed when application_id is available from sealed status.
 * No account private key required.
 *
 * Wire hooks explicitly (no auto-register):
 *
 *   add_action('admin_enqueue_scripts', function ($hook) use ($client) {
 *       InstancePaymentPanel::enqueue($client, array(
 *           'hook_suffix' => $hook,
 *           'settings_page' => 'your_plugin_slug',
 *       ));
 *   });
 *   add_action('admin_init', function () use ($client) {
 *       InstancePaymentPanel::handleDetach($client, $opts);
 *       InstancePaymentPanel::handlePaymentLink($client, $opts);
 *   });
 *   add_action(
 *       'wp_ajax_' . InstancePaymentPanel::DEFAULT_ATTACH_ACTION,
 *       function () use ($client) {
 *           InstancePaymentPanel::handleAttachAjax($client);
 *       }
 *   );
 *   // On the settings page: InstancePaymentPanel::render($client, $opts);
 *
 * @phpstan-type PanelOptions array{
 *     capability?: string,
 *     settings_page?: string,
 *     hook_suffix?: string,
 *     redirect_url?: string,
 *     settings_group?: string,
 *     attach_action?: string,
 *     detach_action?: string,
 *     link_action?: string,
 *     link_transient?: string,
 *     wrapper_id?: string,
 *     wrapper_class?: string,
 *     heading?: string|null,
 *     intro?: string|null,
 *     show_hosted_link?: bool,
 *     card_show_submit?: bool,
 *     card_chain_submit_selector?: string,
 *     no_card_message?: string|null,
 *     echo?: bool
 * }
 */
final class InstancePaymentPanel
{
    public const DEFAULT_CAPABILITY = 'manage_options';
    public const DEFAULT_ATTACH_ACTION = InstanceCardForm::DEFAULT_AJAX_ACTION;
    public const DEFAULT_DETACH_ACTION = 'validakey_detach_instance_card';
    public const DEFAULT_LINK_ACTION = 'validakey_instance_payment_link';
    public const DEFAULT_LINK_TRANSIENT = 'validakey_instance_payment_link';
    public const DEFAULT_SETTINGS_GROUP = 'validakey_license_messages';
    public const DEFAULT_WRAPPER_ID = 'validakey-instance-payment';

    /**
     * Enqueue Square Web Payments when the settings page will show the card form.
     *
     * Pass the current admin `$hook_suffix` and your `settings_page` slug so this
     * no-ops on other screens.
     *
     * @param PanelOptions $options
     */
    public static function enqueue(?ValidakeyClient $client, array $options = array()): void
    {
        $options = self::normalizeOptions($options);

        if (null === $client || ! \current_user_can($options['capability'])) {
            return;
        }

        $settingsPage = $options['settings_page'];
        $hookSuffix = $options['hook_suffix'];
        if ('' !== $settingsPage && '' !== $hookSuffix && $hookSuffix !== 'settings_page_' . $settingsPage) {
            return;
        }

        try {
            $status = $client->instancePaymentStatus();
        } catch (\Throwable $e) {
            return;
        }

        if ($status->hasCard() || null === $status->applicationId()) {
            return;
        }

        InstanceCardForm::enqueue(array(
            'application_id' => $status->applicationId(),
            'location_id' => $status->locationId(),
            'sandbox' => $status->isSandbox(),
            'ajax_action' => $options['attach_action'],
            'nonce' => \function_exists('wp_create_nonce')
                ? (string) \wp_create_nonce($options['attach_action'])
                : '',
            'chain_submit_selector' => $options['card_chain_submit_selector'],
        ));
    }

    /**
     * Echo (or return) the payment-method section markup.
     *
     * @param PanelOptions $options
     */
    public static function render(?ValidakeyClient $client, array $options = array()): string
    {
        $options = self::normalizeOptions($options);
        \ob_start();

        echo '<div id="' . \esc_attr($options['wrapper_id']) . '" class="'
            . \esc_attr($options['wrapper_class']) . '" style="margin:1em 0 1.5em;">';

        if (null !== $options['heading'] && '' !== $options['heading']) {
            echo '<h2 class="title">' . \esc_html($options['heading']) . '</h2>';
        }

        if (null !== $options['intro'] && '' !== $options['intro']) {
            echo '<p>' . \esc_html($options['intro']) . '</p>';
        }

        if (null === $client) {
            echo '<div class="notice notice-error inline"><p>'
                . \esc_html(\__('Validakey is not configured.', 'validakey'))
                . '</p></div></div>';

            return self::finishRender($options);
        }

        try {
            $status = $client->instancePaymentStatus();
        } catch (\Throwable $e) {
            echo '<div class="notice notice-error inline"><p>' . \esc_html($e->getMessage()) . '</p></div></div>';

            return self::finishRender($options);
        }

        $link = \get_transient($options['link_transient']);
        if (\is_array($link) && ! empty($link['url'])) {
            echo '<div class="notice notice-info inline"><p>';
            echo \esc_html(\__('Hosted payment link (opens Square; expires soon):', 'validakey')) . ' ';
            printf(
                '<a href="%1$s" target="_blank" rel="noopener noreferrer">%1$s</a>',
                \esc_url((string) $link['url'])
            );
            echo '</p></div>';
        }

        if ($status->hasCard()) {
            $brand = $status->cardBrand() !== ''
                ? $status->cardBrand()
                : \__('Card', 'validakey');
            $last4 = $status->cardLast4() !== '' ? $status->cardLast4() : '????';
            echo '<p><strong>' . \esc_html(\__('Card on file:', 'validakey')) . '</strong> ';
            echo \esc_html($brand . ' •••• ' . $last4) . '</p>';
            echo '<form method="post" onsubmit="return confirm(\''
                . \esc_js(\__('Remove the card on file for this site?', 'validakey'))
                . '\');">';
            \wp_nonce_field($options['detach_action'], $options['detach_action'] . '_nonce');
            \submit_button(
                \__('Remove card', 'validakey'),
                'delete',
                $options['detach_action'],
                false
            );
            echo '</form>';
        } elseif (null !== $status->applicationId()) {
            $noCardMessage = $options['no_card_message'];
            if (null === $noCardMessage || '' === $noCardMessage) {
                // Standalone card form still gets a short prompt; purchase flow
                // (card_show_submit false) relies on the form + button alone.
                $noCardMessage = $options['card_show_submit']
                    ? \__('No card on file. Enter a card below, then request a priced license.', 'validakey')
                    : '';
            }
            if ('' !== $noCardMessage) {
                echo '<p>' . \esc_html($noCardMessage) . '</p>';
            }
            InstanceCardForm::render(array(
                'show_submit' => $options['card_show_submit'],
            ));
            if ($options['show_hosted_link']) {
                self::renderHostedLinkForm($options['link_action']);
            }
        } else {
            echo '<p>' . \esc_html(\__(
                'Square checkout is not configured on the Validakey server for this app. You can still try a hosted payment link.',
                'validakey'
            )) . '</p>';
            if ($options['show_hosted_link']) {
                self::renderHostedLinkForm($options['link_action']);
            }
        }

        echo '</div>';

        return self::finishRender($options);
    }

    /**
     * AJAX: attach IE card from Square source_id. Sends JSON and exits.
     *
     * @param PanelOptions $options
     */
    public static function handleAttachAjax(?ValidakeyClient $client, array $options = array()): void
    {
        $options = self::normalizeOptions($options);

        if (! \current_user_can($options['capability'])) {
            \wp_send_json_error(array('message' => \__('Forbidden.', 'validakey')), 403);
        }

        $nonce = isset($_POST['nonce'])
            ? \sanitize_text_field(\wp_unslash((string) $_POST['nonce']))
            : '';
        if (! \wp_verify_nonce($nonce, $options['attach_action'])) {
            \wp_send_json_error(array('message' => \__('Invalid nonce.', 'validakey')), 403);
        }

        if (null === $client) {
            \wp_send_json_error(array('message' => \__('Validakey is not configured.', 'validakey')), 400);
        }

        $sourceId = isset($_POST['source_id'])
            ? \sanitize_text_field(\wp_unslash((string) $_POST['source_id']))
            : '';
        if ('' === $sourceId) {
            \wp_send_json_error(array('message' => \__('Missing Square source_id.', 'validakey')), 400);
        }

        $country = isset($_POST['country'])
            ? \sanitize_text_field(\wp_unslash((string) $_POST['country']))
            : 'US';
        $billing = array('country' => '' !== $country ? $country : 'US');
        if (isset($_POST['postal_code']) && '' !== \trim((string) \wp_unslash($_POST['postal_code']))) {
            $billing['postal_code'] = \sanitize_text_field(\wp_unslash((string) $_POST['postal_code']));
        }

        try {
            $result = $client->attachInstanceCard(
                new AttachCardRequest(sourceId: $sourceId, billingFields: $billing)
            );
        } catch (\Throwable $e) {
            \wp_send_json_error(array('message' => $e->getMessage()), 500);
        }

        if (! $result->isOk()) {
            $message = $result->errorMessage() !== ''
                ? $result->errorMessage()
                : \__('Card was declined or could not be saved.', 'validakey');
            \wp_send_json_error(array('message' => $message), 400);
        }

        \wp_send_json_success(array(
            'brand' => $result->cardBrand(),
            'last4' => $result->cardLast4(),
        ));
    }

    /**
     * Process Remove-card POST when present. Redirects and exits when handled.
     *
     * @param PanelOptions $options
     */
    public static function handleDetach(?ValidakeyClient $client, array $options = array()): void
    {
        $options = self::normalizeOptions($options);

        if (! self::shouldHandleAdminPost($options, $options['detach_action'])) {
            return;
        }

        $redirect = self::requireRedirectUrl($options, 'handleDetach');

        if (null === $client) {
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_card_unconfigured',
                \__('Validakey is not configured.', 'validakey'),
                'error'
            );
            self::redirectWithNotices($redirect);

            return;
        }

        $result = $client->detachInstanceCard();
        if ($result->isOk()) {
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_card_detached',
                \__('Payment card removed.', 'validakey'),
                'success'
            );
        } else {
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_card_detach_failed',
                $result->errorMessage() !== ''
                    ? $result->errorMessage()
                    : \__('Could not remove card.', 'validakey'),
                'error'
            );
        }

        self::redirectWithNotices($redirect);
    }

    /**
     * Process hosted-payment-link POST when present. Redirects and exits when handled.
     *
     * @param PanelOptions $options
     */
    public static function handlePaymentLink(?ValidakeyClient $client, array $options = array()): void
    {
        $options = self::normalizeOptions($options);

        if (! self::shouldHandleAdminPost($options, $options['link_action'])) {
            return;
        }

        $redirect = self::requireRedirectUrl($options, 'handlePaymentLink');

        if (null === $client) {
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_link_unconfigured',
                \__('Validakey is not configured.', 'validakey'),
                'error'
            );
            self::redirectWithNotices($redirect);

            return;
        }

        $result = $client->requestInstancePaymentLink();
        if ($result->isOk() && null !== $result->paymentUrl()) {
            $ttl = \defined('MINUTE_IN_SECONDS') ? 15 * (int) \MINUTE_IN_SECONDS : 900;
            \set_transient(
                $options['link_transient'],
                array(
                    'url' => $result->paymentUrl(),
                    'expires_at' => $result->expiresAt(),
                ),
                $ttl
            );
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_payment_link',
                \__('Hosted payment link ready — open it below.', 'validakey'),
                'success'
            );
        } else {
            self::addNotice(
                $options['settings_group'],
                'validakey_ie_payment_link_failed',
                $result->errorMessage() !== ''
                    ? $result->errorMessage()
                    : \__('Could not create payment link.', 'validakey'),
                'error'
            );
        }

        self::redirectWithNotices($redirect);
    }

    /**
     * @param PanelOptions $options
     * @return array{
     *     capability: string,
     *     settings_page: string,
     *     hook_suffix: string,
     *     redirect_url: string,
     *     settings_group: string,
     *     attach_action: string,
     *     detach_action: string,
     *     link_action: string,
     *     link_transient: string,
     *     wrapper_id: string,
     *     wrapper_class: string,
     *     heading: string|null,
     *     intro: string|null,
     *     show_hosted_link: bool,
     *     card_show_submit: bool,
     *     card_chain_submit_selector: string,
     *     no_card_message: string|null,
     *     echo: bool
     * }
     */
    private static function normalizeOptions(array $options): array
    {
        return array(
            'capability' => isset($options['capability']) && '' !== (string) $options['capability']
                ? (string) $options['capability']
                : self::DEFAULT_CAPABILITY,
            'settings_page' => isset($options['settings_page'])
                ? (string) $options['settings_page']
                : '',
            'hook_suffix' => isset($options['hook_suffix'])
                ? (string) $options['hook_suffix']
                : '',
            'redirect_url' => isset($options['redirect_url'])
                ? (string) $options['redirect_url']
                : '',
            'settings_group' => isset($options['settings_group']) && '' !== (string) $options['settings_group']
                ? (string) $options['settings_group']
                : self::DEFAULT_SETTINGS_GROUP,
            'attach_action' => isset($options['attach_action']) && '' !== (string) $options['attach_action']
                ? (string) $options['attach_action']
                : self::DEFAULT_ATTACH_ACTION,
            'detach_action' => isset($options['detach_action']) && '' !== (string) $options['detach_action']
                ? (string) $options['detach_action']
                : self::DEFAULT_DETACH_ACTION,
            'link_action' => isset($options['link_action']) && '' !== (string) $options['link_action']
                ? (string) $options['link_action']
                : self::DEFAULT_LINK_ACTION,
            'link_transient' => isset($options['link_transient']) && '' !== (string) $options['link_transient']
                ? (string) $options['link_transient']
                : self::DEFAULT_LINK_TRANSIENT,
            'wrapper_id' => isset($options['wrapper_id']) && '' !== (string) $options['wrapper_id']
                ? (string) $options['wrapper_id']
                : self::DEFAULT_WRAPPER_ID,
            'wrapper_class' => isset($options['wrapper_class']) && '' !== (string) $options['wrapper_class']
                ? (string) $options['wrapper_class']
                : 'validakey-instance-payment',
            'heading' => \array_key_exists('heading', $options)
                ? (null === $options['heading'] ? null : (string) $options['heading'])
                : \__('Payment method', 'validakey'),
            'intro' => \array_key_exists('intro', $options)
                ? (null === $options['intro'] ? null : (string) $options['intro'])
                : \__(
                    'Priced licenses charge the site (Instance Entity). Add a card with Square’s embedded form — the card number never touches WordPress or Validakey. No account private key is required.',
                    'validakey'
                ),
            'show_hosted_link' => ! isset($options['show_hosted_link']) || (bool) $options['show_hosted_link'],
            'card_show_submit' => ! isset($options['card_show_submit']) || (bool) $options['card_show_submit'],
            'card_chain_submit_selector' => isset($options['card_chain_submit_selector'])
                ? trim((string) $options['card_chain_submit_selector'])
                : '',
            'no_card_message' => \array_key_exists('no_card_message', $options)
                ? (null === $options['no_card_message'] ? null : (string) $options['no_card_message'])
                : null,
            'echo' => ! isset($options['echo']) || (bool) $options['echo'],
        );
    }

    /**
     * @param array{capability: string, settings_page: string} $options
     */
    private static function shouldHandleAdminPost(array $options, string $action): bool
    {
        if (! \is_admin() || ! \current_user_can($options['capability'])) {
            return false;
        }

        if (! isset($_POST[$action])) {
            return false;
        }

        $settingsPage = $options['settings_page'];
        if ('' !== $settingsPage) {
            $page = isset($_GET['page'])
                ? \sanitize_key(\wp_unslash((string) $_GET['page']))
                : '';
            if ($settingsPage !== $page) {
                return false;
            }
        }

        $nonceField = $action . '_nonce';
        if (
            ! isset($_POST[$nonceField])
            || ! \wp_verify_nonce(
                \sanitize_text_field(\wp_unslash((string) $_POST[$nonceField])),
                $action
            )
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param array{redirect_url: string, wrapper_id: string} $options
     */
    private static function requireRedirectUrl(array $options, string $method): string
    {
        $redirect = $options['redirect_url'];
        if ('' === $redirect) {
            throw new \InvalidArgumentException(
                'InstancePaymentPanel::' . $method . '() requires options[redirect_url].'
            );
        }

        if (! \str_contains($redirect, '#')) {
            $redirect .= '#' . $options['wrapper_id'];
        }

        return $redirect;
    }

    private static function renderHostedLinkForm(string $linkAction): void
    {
        echo '<p class="description">' . \esc_html(\__('Or use a hosted payment page:', 'validakey')) . '</p>';
        echo '<form method="post">';
        \wp_nonce_field($linkAction, $linkAction . '_nonce');
        \submit_button(
            \__('Open hosted payment link', 'validakey'),
            'secondary',
            $linkAction,
            false
        );
        echo '</form>';
    }

    /**
     * @param array{echo: bool} $options
     */
    private static function finishRender(array $options): string
    {
        $html = (string) \ob_get_clean();
        if ($options['echo']) {
            echo $html;
        }

        return $html;
    }

    private static function addNotice(string $group, string $code, string $message, string $type): void
    {
        if (\function_exists('add_settings_error')) {
            \add_settings_error($group, $code, $message, $type);
        }
    }

    private static function redirectWithNotices(string $url): void
    {
        if (\function_exists('get_settings_errors') && \count(\get_settings_errors()) > 0) {
            \set_transient('settings_errors', \get_settings_errors(), 30);
        }
        \wp_safe_redirect(\add_query_arg('settings-updated', 'true', $url));
        exit;
    }
}
