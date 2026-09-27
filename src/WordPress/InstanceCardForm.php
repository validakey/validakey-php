<?php

declare(strict_types=1);

namespace Validakey\WordPress;

/**
 * Enqueue Square Web Payments and render an Instance Entity card form.
 *
 * Public Square application_id / location_id come from
 * {@see \Validakey\ValidakeyClient::instancePaymentStatus()} (sealed; no apiPKey).
 * The browser tokenizes with Square's CDN SDK; only the source_id returns to PHP
 * for {@see \Validakey\ValidakeyClient::attachInstanceCard()}.
 */
final class InstanceCardForm
{
    public const SCRIPT_HANDLE = 'validakey-instance-card-form';
    public const DEFAULT_AJAX_ACTION = 'validakey_attach_instance_card';

    /**
     * @param array{
     *     application_id: string,
     *     location_id?: string|null,
     *     sandbox?: bool,
     *     ajax_url?: string,
     *     ajax_action?: string,
     *     nonce?: string,
     *     container_id?: string,
     *     form_id?: string,
     *     script_url?: string|null,
     *     chain_submit_selector?: string
     * } $config
     */
    public static function enqueue(array $config): void
    {
        if (! \function_exists('wp_enqueue_script')) {
            throw new \RuntimeException('InstanceCardForm::enqueue() requires WordPress.');
        }

        $applicationId = isset($config['application_id']) ? trim((string) $config['application_id']) : '';
        if ('' === $applicationId) {
            throw new \InvalidArgumentException('InstanceCardForm requires application_id.');
        }

        $sandbox = ! empty($config['sandbox']);
        $squareHandle = $sandbox ? 'square-web-payments-sandbox' : 'square-web-payments';
        $squareSrc = $sandbox
            ? 'https://sandbox.web.squarecdn.com/v1/square.js'
            : 'https://web.squarecdn.com/v1/square.js';

        \wp_enqueue_script($squareHandle, $squareSrc, array(), null, true);

        $scriptUrl = isset($config['script_url']) && \is_string($config['script_url']) && '' !== $config['script_url']
            ? $config['script_url']
            : self::defaultScriptUrl();

        \wp_enqueue_script(
            self::SCRIPT_HANDLE,
            $scriptUrl,
            array($squareHandle),
            '1.9.2',
            true
        );

        $ajaxAction = isset($config['ajax_action']) && '' !== trim((string) $config['ajax_action'])
            ? trim((string) $config['ajax_action'])
            : self::DEFAULT_AJAX_ACTION;

        $nonce = isset($config['nonce']) ? (string) $config['nonce'] : '';
        if ('' === $nonce && \function_exists('wp_create_nonce')) {
            $nonce = (string) \wp_create_nonce($ajaxAction);
        }

        $ajaxUrl = isset($config['ajax_url']) && '' !== (string) $config['ajax_url']
            ? (string) $config['ajax_url']
            : (string) \admin_url('admin-ajax.php');

        \wp_localize_script(self::SCRIPT_HANDLE, 'validakeyInstanceCardForm', array(
            'applicationId' => $applicationId,
            'locationId' => isset($config['location_id']) && null !== $config['location_id'] && '' !== (string) $config['location_id']
                ? (string) $config['location_id']
                : '',
            'sandbox' => $sandbox,
            'ajaxUrl' => $ajaxUrl,
            'ajaxAction' => $ajaxAction,
            'nonce' => $nonce,
            'containerId' => isset($config['container_id']) ? (string) $config['container_id'] : 'validakey-ie-card-container',
            'formId' => isset($config['form_id']) ? (string) $config['form_id'] : 'validakey-ie-card-form',
            'chainSubmitSelector' => isset($config['chain_submit_selector'])
                ? (string) $config['chain_submit_selector']
                : '',
            'i18n' => array(
                'notReady' => \__('Card form is not ready.', 'validakey'),
                'tokenizeFailed' => \__('Card tokenization failed.', 'validakey'),
                'saveFailed' => \__('Could not save card.', 'validakey'),
                'saved' => \__('Card saved.', 'validakey'),
            ),
        ));
    }

    /**
     * Echo the card form markup (container + optional submit). Call after enqueue on the same request.
     *
     * @param array{container_id?: string, form_id?: string, submit_label?: string, show_submit?: bool} $options
     */
    public static function render(array $options = array()): void
    {
        $containerId = isset($options['container_id']) ? (string) $options['container_id'] : 'validakey-ie-card-container';
        $formId = isset($options['form_id']) ? (string) $options['form_id'] : 'validakey-ie-card-form';
        $showSubmit = ! isset($options['show_submit']) || (bool) $options['show_submit'];
        $submitLabel = isset($options['submit_label']) && '' !== (string) $options['submit_label']
            ? (string) $options['submit_label']
            : \__('Save card', 'validakey');

        echo '<form id="' . \esc_attr($formId) . '" class="validakey-ie-card-form" method="post" action="#">';
        echo '<div id="' . \esc_attr($containerId) . '" class="validakey-ie-card-container" style="min-height:56px;margin:0.75em 0;"></div>';
        echo '<p class="validakey-ie-card-error" hidden style="color:#b32d2e;"></p>';
        if ($showSubmit) {
            echo '<p class="submit" style="margin-top:0.5em;">';
            echo '<button type="submit" class="button button-primary">' . \esc_html($submitLabel) . '</button>';
            echo '</p>';
        }
        echo '</form>';
    }

    public static function defaultScriptUrl(): string
    {
        $file = __DIR__ . '/assets/instance-card-form.js';
        if (\function_exists('content_url') && \defined('WP_CONTENT_DIR')) {
            $realFile = \realpath($file);
            $realContent = \realpath((string) \WP_CONTENT_DIR);
            if (false !== $realFile && false !== $realContent && \str_starts_with($realFile, $realContent)) {
                $relative = \str_replace('\\', '/', \substr($realFile, \strlen($realContent)));

                return \content_url($relative);
            }
        }

        return 'file://' . $file;
    }
}
