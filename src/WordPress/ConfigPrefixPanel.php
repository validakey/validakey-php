<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Envelope;
use Validakey\ValidakeyClient;

/**
 * Admin helper that shows the cleartext lookup prefixes for the configured
 * account UUID ({@see ValidakeyConfig::$apiUUID}) and User App ID
 * ({@see ValidakeyConfig::$userAppId}) as a single status line.
 *
 * These are the same {@see Envelope::PREFIX_LEN}-character prefixes used on the
 * wire — useful for confirming which Validakey account/app a plugin build is
 * talking to without printing full credentials.
 *
 *   ConfigPrefixPanel::render($client, array(
 *       'wrapper_class' => 'myplugin-config-prefix',
 *   ));
 *
 * @phpstan-type PanelOptions array{
 *     capability?: string,
 *     wrapper_id?: string,
 *     wrapper_class?: string,
 *     heading?: string|null,
 *     unconfigured_message?: string,
 *     echo?: bool
 * }
 */
final class ConfigPrefixPanel
{
    public const DEFAULT_CAPABILITY = 'manage_options';
    public const DEFAULT_WRAPPER_ID = 'validakey-config-prefix';

    /**
     * @param PanelOptions $options
     */
    public static function render(?ValidakeyClient $client, array $options = array()): string
    {
        $options = self::normalizeOptions($options);
        \ob_start();

        echo '<div id="' . \esc_attr($options['wrapper_id']) . '" class="'
            . \esc_attr($options['wrapper_class']) . '">';

        if (
            \function_exists('current_user_can')
            && ! \current_user_can($options['capability'])
        ) {
            echo '<p>' . \esc_html(\__('You do not have permission to view this.', 'validakey')) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        if (null === $client) {
            $label = null !== $options['heading'] && '' !== $options['heading']
                ? $options['heading'] . ': '
                : '';
            echo '<p class="description">' . \esc_html($label . $options['unconfigured_message']) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        $context = $client->applicationContext();
        $uuidPrefix = Envelope::prefix($context->apiUUID);
        $appIdPrefix = Envelope::prefix($context->userAppId);

        echo '<p class="validakey-config-prefix-line" style="margin:0.5em 0;">';
        if (null !== $options['heading'] && '' !== $options['heading']) {
            echo '<strong>' . \esc_html($options['heading']) . ':</strong> ';
        }
        echo \esc_html(\__('Account UUID', 'validakey'))
            . ' <code>' . \esc_html($uuidPrefix) . '</code>';
        echo ' <span aria-hidden="true">·</span> ';
        echo \esc_html(\__('App ID', 'validakey'))
            . ' <code>' . \esc_html($appIdPrefix) . '</code>';
        echo '</p>';

        echo '</div>';

        return self::finishRender($options);
    }

    /**
     * @param PanelOptions $options
     * @return array{
     *     capability: string,
     *     wrapper_id: string,
     *     wrapper_class: string,
     *     heading: string|null,
     *     unconfigured_message: string,
     *     echo: bool
     * }
     */
    private static function normalizeOptions(array $options): array
    {
        return array(
            'capability' => isset($options['capability']) && '' !== (string) $options['capability']
                ? (string) $options['capability']
                : self::DEFAULT_CAPABILITY,
            'wrapper_id' => isset($options['wrapper_id']) && '' !== (string) $options['wrapper_id']
                ? (string) $options['wrapper_id']
                : self::DEFAULT_WRAPPER_ID,
            'wrapper_class' => isset($options['wrapper_class']) && '' !== (string) $options['wrapper_class']
                ? (string) $options['wrapper_class']
                : 'validakey-config-prefix',
            'heading' => \array_key_exists('heading', $options)
                ? (null === $options['heading'] ? null : (string) $options['heading'])
                : \__('Validakey', 'validakey'),
            'unconfigured_message' => isset($options['unconfigured_message']) && '' !== trim((string) $options['unconfigured_message'])
                ? (string) $options['unconfigured_message']
                : \__('not configured.', 'validakey'),
            'echo' => ! isset($options['echo']) || (bool) $options['echo'],
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
}
