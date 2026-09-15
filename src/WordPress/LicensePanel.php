<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;
use Validakey\Exception\ValidakeyException;
use Validakey\License;
use Validakey\Response\LicenseStatus;

/**
 * Admin settings panel for a {@see License}: status table and Request button.
 *
 * Drop this onto a plugin settings tab — not a public shortcode. Capability
 * defaults to manage_options. The caller supplies redirect URL and usually
 * wires handleRequest() on admin_init.
 *
 * @phpstan-type PanelOptions array{
 *     capability?: string,
 *     action?: string,
 *     nonce_field?: string,
 *     redirect_url?: string,
 *     error_transient?: string,
 *     settings_group?: string,
 *     heading?: string|null,
 *     configured?: bool,
 *     unconfigured_message?: string,
 *     wrapper_class?: string,
 *     echo?: bool
 * }
 */
final class LicensePanel
{
    public const DEFAULT_CAPABILITY = 'manage_options';
    public const DEFAULT_ACTION = 'validakey_license_request';
    public const DEFAULT_ERROR_TRANSIENT = 'validakey_license_last_error';
    public const DEFAULT_SETTINGS_GROUP = 'validakey_license_messages';

    /**
     * Process a Request POST when present. Redirects and exits on success or
     * after recording an error. Call from admin_init before headers are sent.
     *
     * @param PanelOptions $options
     */
    public static function handleRequest(?License $license, array $options = array()): void
    {
        $options = self::normalizeOptions($options);

        if (! \is_admin() || ! \current_user_can($options['capability'])) {
            return;
        }

        $action = $options['action'];
        if (! isset($_POST[$action])) {
            return;
        }

        $nonceField = $options['nonce_field'];
        if (
            ! isset($_POST[$nonceField])
            || ! \wp_verify_nonce(
                \sanitize_text_field(\wp_unslash((string) $_POST[$nonceField])),
                $action
            )
        ) {
            return;
        }

        $redirect = $options['redirect_url'];
        if ('' === $redirect) {
            throw new \InvalidArgumentException(
                'LicensePanel::handleRequest() requires options[redirect_url].'
            );
        }

        if (! $options['configured'] || null === $license) {
            self::addNotice(
                $options['settings_group'],
                'validakey_license_unconfigured',
                $options['unconfigured_message'],
                'error'
            );
            self::redirectWithNotices($redirect);

            return;
        }

        try {
            $license->request();
            \delete_transient($options['error_transient']);
            self::addNotice(
                $options['settings_group'],
                'validakey_license_granted',
                \__('License granted.', 'validakey'),
                'success'
            );
            self::redirectWithNotices($redirect);
        } catch (PaymentRequiredException $e) {
            self::rememberError($options['error_transient'], self::paymentNotice($e));
        } catch (ApiException $e) {
            self::rememberError($options['error_transient'], self::apiNotice($e));
        } catch (ValidakeyException $e) {
            self::rememberError($options['error_transient'], $e->getMessage());
        }

        \wp_safe_redirect($redirect);
        exit;
    }

    /**
     * Render the status table, last error (if any), and Request button when
     * the subject has no valid grant.
     *
     * @param PanelOptions $options
     */
    public static function render(?License $license, array $options = array()): string
    {
        $options = self::normalizeOptions($options);
        \ob_start();

        echo '<div class="' . \esc_attr($options['wrapper_class']) . '">';

        if (null !== $options['heading'] && '' !== $options['heading']) {
            echo '<h2 class="title">' . \esc_html($options['heading']) . '</h2>';
        }

        if (! $options['configured'] || null === $license) {
            echo '<p>' . \esc_html($options['unconfigured_message']) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        try {
            $status = $license->status();
        } catch (PaymentRequiredException $e) {
            echo '<p class="validakey-license-error">' . \esc_html(self::paymentNotice($e)) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        } catch (ValidakeyException $e) {
            echo '<p class="validakey-license-error">' . \esc_html($e->getMessage()) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        self::renderStatusTable($status, $license);

        $lastError = \get_transient($options['error_transient']);
        if (\is_string($lastError) && '' !== $lastError) {
            echo '<div class="notice notice-error inline validakey-license-last-error"><p>'
                . \esc_html($lastError)
                . '</p></div>';
        }

        if (! $status->isGranted()) {
            echo '<form method="post" class="validakey-license-request-form">';
            \wp_nonce_field($options['action'], $options['nonce_field']);
            \submit_button(\__('Request', 'validakey'), 'primary', $options['action'], false);
            echo '</form>';
        }

        echo '</div>';

        return self::finishRender($options);
    }

    /**
     * @param PanelOptions $options
     *
     * @return array{
     *     capability: string,
     *     action: string,
     *     nonce_field: string,
     *     redirect_url: string,
     *     error_transient: string,
     *     settings_group: string,
     *     heading: string|null,
     *     configured: bool,
     *     unconfigured_message: string,
     *     wrapper_class: string,
     *     echo: bool
     * }
     */
    private static function normalizeOptions(array $options): array
    {
        $action = isset($options['action']) && '' !== trim((string) $options['action'])
            ? trim((string) $options['action'])
            : self::DEFAULT_ACTION;

        return array(
            'capability' => isset($options['capability']) && '' !== trim((string) $options['capability'])
                ? trim((string) $options['capability'])
                : self::DEFAULT_CAPABILITY,
            'action' => $action,
            'nonce_field' => isset($options['nonce_field']) && '' !== trim((string) $options['nonce_field'])
                ? trim((string) $options['nonce_field'])
                : $action . '_nonce',
            'redirect_url' => isset($options['redirect_url']) ? (string) $options['redirect_url'] : '',
            'error_transient' => isset($options['error_transient']) && '' !== trim((string) $options['error_transient'])
                ? trim((string) $options['error_transient'])
                : self::DEFAULT_ERROR_TRANSIENT,
            'settings_group' => isset($options['settings_group']) && '' !== trim((string) $options['settings_group'])
                ? trim((string) $options['settings_group'])
                : self::DEFAULT_SETTINGS_GROUP,
            'heading' => \array_key_exists('heading', $options)
                ? (null === $options['heading'] ? null : (string) $options['heading'])
                : \__('License', 'validakey'),
            'configured' => ! \array_key_exists('configured', $options) || (bool) $options['configured'],
            'unconfigured_message' => isset($options['unconfigured_message']) && '' !== trim((string) $options['unconfigured_message'])
                ? (string) $options['unconfigured_message']
                : \__('Validakey is not configured. License-path constants (VALIDAKEY_BASE_URL, VALIDAKEY_API_UUID, VALIDAKEY_USER_APP_ID) must ship with the plugin.', 'validakey'),
            'wrapper_class' => isset($options['wrapper_class']) && '' !== trim((string) $options['wrapper_class'])
                ? trim((string) $options['wrapper_class'])
                : 'validakey-license-panel',
            'echo' => ! \array_key_exists('echo', $options) || (bool) $options['echo'],
        );
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

    private static function renderStatusTable(LicenseStatus $status, License $license): void
    {
        $subject = $status->subject;
        if ('' === $subject) {
            $host = \function_exists('wp_parse_url')
                ? \wp_parse_url(\home_url(), PHP_URL_HOST)
                : \parse_url(\home_url(), PHP_URL_HOST);
            $subject = \is_string($host) && '' !== $host
                ? $host
                : \__('(default)', 'validakey');
        }

        $expires = '—';
        if ($status->isGranted()) {
            $expires = null === $status->expiresAt()
                ? \__('Never', 'validakey')
                : self::formatTimestamp($status->expiresAt());
        } elseif (null !== $status->expiresAt()) {
            $expires = self::formatTimestamp($status->expiresAt());
        }

        echo '<table class="widefat striped validakey-license-status" role="presentation"><tbody>';
        self::statusRow(\__('Subject', 'validakey'), $subject);
        self::statusRow(\__('Status', 'validakey'), self::grantLabel($status));
        self::statusRow(\__('Expires', 'validakey'), $expires);
        if (null !== $status->usesRemaining()) {
            self::statusRow(\__('Uses remaining', 'validakey'), (string) $status->usesRemaining());
        }
        $locator = $license->tokenLocator();
        if (null !== $locator) {
            self::statusRow(\__('Token Locator', 'validakey'), $locator);
        }
        echo '</tbody></table>';
    }

    public static function grantLabel(LicenseStatus $status): string
    {
        if ($status->isGranted()) {
            return \__('Valid', 'validakey');
        }

        return match ($status->reason()) {
            'none' => \__('Not granted', 'validakey'),
            'revoked' => \__('Revoked', 'validakey'),
            'expired' => \__('Expired', 'validakey'),
            'depleted' => \__('Depleted', 'validakey'),
            'not_found' => \__('Not found', 'validakey'),
            default => '' !== $status->reason() ? $status->reason() : \__('Not granted', 'validakey'),
        };
    }

    public static function paymentNotice(PaymentRequiredException $e): string
    {
        $code = (string) $e->errorCode;
        if ('ie_card_required' === $code) {
            return \__('This license requires a payment card for the site before a token can be granted.', 'validakey');
        }
        if ('dues_past_due' === $code) {
            return \__('The publisher Validakey account has an unpaid service fee. Token minting is blocked until that is current.', 'validakey');
        }

        return \__('The publisher Validakey account needs a card on file before a license can be granted.', 'validakey');
    }

    public static function apiNotice(ApiException $e): string
    {
        $code = $e->errorCode ? $e->errorCode : 'error';
        $status = (int) $e->httpStatus;
        $lines = array(
            \sprintf(
                /* translators: 1: HTTP status, 2: error code */
                \__('License request failed (HTTP %1$d, %2$s).', 'validakey'),
                $status,
                $code
            ),
        );

        $message = \trim($e->getMessage());
        if ('' !== $message) {
            $lines[] = $message;
        }

        $snippet = \is_string($e->data) ? \trim($e->data) : '';
        if ('' !== $snippet) {
            if (self::looksLikeHtml($snippet)) {
                $lines[] = \__('The Validakey base URL returned an HTML page instead of the JSON API. Check VALIDAKEY_BASE_URL.', 'validakey');
                $title = self::htmlTitle($snippet);
                if ('' !== $title) {
                    $lines[] = \sprintf(
                        /* translators: %s: HTML document title */
                        \__('Page title: %s', 'validakey'),
                        $title
                    );
                }
            }
            $lines[] = \sprintf(
                /* translators: %s: truncated server body */
                \__('Server reply: %s', 'validakey'),
                $snippet
            );
        }

        return \implode("\n", $lines);
    }

    private static function statusRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . \esc_html($label) . '</th><td>' . \esc_html($value) . '</td></tr>';
    }

    private static function formatTimestamp(int $timestamp): string
    {
        if (\function_exists('wp_date')) {
            $format = \get_option('date_format') . ' ' . \get_option('time_format');

            return (string) \wp_date($format, $timestamp);
        }

        return \gmdate('Y-m-d H:i:s', $timestamp) . ' UTC';
    }

    private static function looksLikeHtml(string $snippet): bool
    {
        return (bool) \preg_match('/^<!DOCTYPE html|<html[\s>]/i', \ltrim($snippet));
    }

    private static function htmlTitle(string $snippet): string
    {
        if (1 !== \preg_match('/<title[^>]*>([^<]+)<\/title>/i', $snippet, $matches)) {
            return '';
        }

        return \html_entity_decode(\trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function rememberError(string $transient, string $message): void
    {
        $ttl = \defined('DAY_IN_SECONDS') ? (int) \DAY_IN_SECONDS : 86400;
        \set_transient($transient, $message, $ttl);
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
