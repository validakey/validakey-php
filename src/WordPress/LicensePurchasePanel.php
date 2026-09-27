<?php

declare(strict_types=1);

namespace Validakey\WordPress;

use Validakey\Exception\ApiException;
use Validakey\Exception\PaymentRequiredException;
use Validakey\Exception\ValidakeyException;
use Validakey\License;
use Validakey\Response\MintPolicyResponse;
use Validakey\ValidakeyClient;

/**
 * One admin panel for software-license purchase: mint policy pricing, IE card
 * form, and Purchase / Request (plus status + revoke when already granted).
 *
 * Composes {@see InstancePaymentPanel} and {@see LicensePanel}. Payment hooks
 * are wired explicitly (no auto-register). Request / Delete POSTs still need
 * {@see LicenseBootstrap::register()} or manual {@see LicensePanel::handleRequest()}.
 *
 *   $opts = array(
 *       'settings_page' => 'myplugin',
 *       'redirect_url' => admin_url('options-general.php?page=myplugin'),
 *   );
 *   add_action('admin_enqueue_scripts', function ($hook) use ($opts) {
 *       LicensePurchasePanel::enqueue(
 *           LicenseBootstrap::license()->client(),
 *           $opts + array('hook_suffix' => $hook)
 *       );
 *   });
 *   // admin_init: handleDetach
 *   // wp_ajax_*: handleAttachAjax
 *   LicensePurchasePanel::render(LicenseBootstrap::license(), $opts);
 *
 * Hosted payment links are not shown here (embed-only). Use
 * {@see InstancePaymentPanel} directly if you need the hosted-link fallback.
 *
 * @phpstan-type PanelOptions array{
 *     capability?: string,
 *     settings_page?: string,
 *     hook_suffix?: string,
 *     redirect_url?: string,
 *     settings_group?: string,
 *     action?: string,
 *     delete_action?: string,
 *     nonce_field?: string,
 *     error_transient?: string,
 *     attach_action?: string,
 *     detach_action?: string,
 *     wrapper_id?: string,
 *     wrapper_class?: string,
 *     payment_wrapper_id?: string,
 *     heading?: string|null,
 *     intro?: string|null,
 *     payment_heading?: string|null,
 *     payment_intro?: string|null,
 *     configured?: bool,
 *     unconfigured_message?: string,
 *     echo?: bool
 * }
 */
final class LicensePurchasePanel
{
    public const DEFAULT_ATTACH_ACTION = InstancePaymentPanel::DEFAULT_ATTACH_ACTION;
    public const DEFAULT_DETACH_ACTION = InstancePaymentPanel::DEFAULT_DETACH_ACTION;
    public const DEFAULT_WRAPPER_ID = 'validakey-license-purchase';

    /**
     * @param PanelOptions $options
     */
    public static function enqueue(?ValidakeyClient $client, array $options = array()): void
    {
        InstancePaymentPanel::enqueue($client, self::paymentOptions($options));
    }

    /**
     * @param PanelOptions $options
     */
    public static function handleAttachAjax(?ValidakeyClient $client, array $options = array()): void
    {
        InstancePaymentPanel::handleAttachAjax($client, self::paymentOptions($options));
    }

    /**
     * @param PanelOptions $options
     */
    public static function handleDetach(?ValidakeyClient $client, array $options = array()): void
    {
        InstancePaymentPanel::handleDetach($client, self::paymentOptions($options));
    }

    /**
     * @param PanelOptions $options
     */
    public static function render(?License $license, array $options = array()): string
    {
        $options = self::normalizeOptions($options);
        \ob_start();

        echo '<div id="' . \esc_attr($options['wrapper_id']) . '" class="'
            . \esc_attr($options['wrapper_class']) . '">';

        if (null !== $options['heading'] && '' !== $options['heading']) {
            echo '<h2 class="title">' . \esc_html($options['heading']) . '</h2>';
        }

        if (null !== $options['intro'] && '' !== $options['intro']) {
            echo '<p>' . \esc_html($options['intro']) . '</p>';
        }

        if (! $options['configured'] || null === $license) {
            echo '<p>' . \esc_html($options['unconfigured_message']) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        try {
            $status = $license->status();
        } catch (PaymentRequiredException $e) {
            echo '<p class="validakey-license-error">' . \esc_html(LicensePanel::paymentNotice($e)) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        } catch (ValidakeyException $e) {
            echo '<p class="validakey-license-error">' . \esc_html($e->getMessage()) . '</p>';
            echo '</div>';

            return self::finishRender($options);
        }

        if ($status->isGranted()) {
            echo LicensePanel::render($license, array(
                'echo' => false,
                'configured' => true,
                'heading' => null,
                'action' => $options['action'],
                'delete_action' => $options['delete_action'],
                'nonce_field' => $options['nonce_field'],
                'error_transient' => $options['error_transient'],
                'settings_group' => $options['settings_group'],
                'capability' => $options['capability'],
                'redirect_url' => $options['redirect_url'],
                'wrapper_class' => 'validakey-license-purchase-status',
            ));
            echo '</div>';

            return self::finishRender($options);
        }

        $loaded = self::loadPolicy($license);
        $policy = $loaded['policy'];
        $policyError = $loaded['error'];
        self::renderPolicySummary($policy, $policyError);

        $lastError = \get_transient($options['error_transient']);
        if (\is_string($lastError) && '' !== $lastError) {
            if (\str_contains($lastError, 'payment card for the site')) {
                $lastError .= ' ' . \sprintf(
                    /* translators: %s: anchor link to payment section */
                    \__('Add a card under %s, then try again.', 'validakey'),
                    '<a href="#' . \esc_attr($options['payment_wrapper_id']) . '">'
                        . \esc_html(\__('Payment method', 'validakey'))
                        . '</a>'
                );
            }
            echo '<div class="notice notice-error inline validakey-license-last-error"><p>'
                . \wp_kses_post($lastError)
                . '</p></div>';
        }

        $priced = self::policyLooksPriced($policy);
        if ($priced) {
            $paymentHtml = InstancePaymentPanel::render(
                $license->client(),
                self::paymentOptions($options)
            );
            echo $paymentHtml;
        }

        $purchaseLabel = self::purchaseButtonLabel($policy);
        echo '<form method="post" class="validakey-license-purchase-form" style="margin-top:1em;"'
            . ' data-validakey-require-card="' . \esc_attr($priced ? '1' : '0') . '">';
        \wp_nonce_field($options['action'], $options['nonce_field']);
        \submit_button($purchaseLabel, 'primary', $options['action'], false);
        echo '</form>';

        echo '</div>';

        return self::finishRender($options);
    }

    /**
     * @param PanelOptions $options
     * @return array<string, mixed>
     */
    private static function paymentOptions(array $options): array
    {
        $normalized = self::normalizeOptions($options);

        return array(
            'capability' => $normalized['capability'],
            'settings_page' => $normalized['settings_page'],
            'hook_suffix' => $normalized['hook_suffix'],
            'redirect_url' => $normalized['redirect_url'],
            'settings_group' => $normalized['settings_group'],
            'attach_action' => $normalized['attach_action'],
            'detach_action' => $normalized['detach_action'],
            'wrapper_id' => $normalized['payment_wrapper_id'],
            'wrapper_class' => 'validakey-instance-payment',
            'heading' => $normalized['payment_heading'],
            'intro' => $normalized['payment_intro'],
            'show_hosted_link' => false,
            // Combined Purchase/Request button saves the card when the embed is shown.
            'card_show_submit' => false,
            'card_chain_submit_selector' => '.validakey-license-purchase-form',
            // Caller captures return value and echoes once.
            'echo' => false,
        );
    }

    /**
     * @param PanelOptions $options
     * @return array{
     *     capability: string,
     *     settings_page: string,
     *     hook_suffix: string,
     *     redirect_url: string,
     *     settings_group: string,
     *     action: string,
     *     delete_action: string,
     *     nonce_field: string,
     *     error_transient: string,
     *     attach_action: string,
     *     detach_action: string,
     *     wrapper_id: string,
     *     wrapper_class: string,
     *     payment_wrapper_id: string,
     *     heading: string|null,
     *     intro: string|null,
     *     payment_heading: string|null,
     *     payment_intro: string|null,
     *     configured: bool,
     *     unconfigured_message: string,
     *     echo: bool
     * }
     */
    private static function normalizeOptions(array $options): array
    {
        $action = isset($options['action']) && '' !== trim((string) $options['action'])
            ? trim((string) $options['action'])
            : LicensePanel::DEFAULT_ACTION;

        return array(
            'capability' => isset($options['capability']) && '' !== (string) $options['capability']
                ? (string) $options['capability']
                : LicensePanel::DEFAULT_CAPABILITY,
            'settings_page' => isset($options['settings_page']) ? (string) $options['settings_page'] : '',
            'hook_suffix' => isset($options['hook_suffix']) ? (string) $options['hook_suffix'] : '',
            'redirect_url' => isset($options['redirect_url']) ? (string) $options['redirect_url'] : '',
            'settings_group' => isset($options['settings_group']) && '' !== (string) $options['settings_group']
                ? (string) $options['settings_group']
                : LicensePanel::DEFAULT_SETTINGS_GROUP,
            'action' => $action,
            'delete_action' => isset($options['delete_action']) && '' !== (string) $options['delete_action']
                ? (string) $options['delete_action']
                : LicensePanel::DEFAULT_DELETE_ACTION,
            'nonce_field' => isset($options['nonce_field']) && '' !== (string) $options['nonce_field']
                ? (string) $options['nonce_field']
                : $action . '_nonce',
            'error_transient' => isset($options['error_transient']) && '' !== (string) $options['error_transient']
                ? (string) $options['error_transient']
                : LicensePanel::DEFAULT_ERROR_TRANSIENT,
            'attach_action' => isset($options['attach_action']) && '' !== (string) $options['attach_action']
                ? (string) $options['attach_action']
                : self::DEFAULT_ATTACH_ACTION,
            'detach_action' => isset($options['detach_action']) && '' !== (string) $options['detach_action']
                ? (string) $options['detach_action']
                : self::DEFAULT_DETACH_ACTION,
            'wrapper_id' => isset($options['wrapper_id']) && '' !== (string) $options['wrapper_id']
                ? (string) $options['wrapper_id']
                : self::DEFAULT_WRAPPER_ID,
            'wrapper_class' => isset($options['wrapper_class']) && '' !== (string) $options['wrapper_class']
                ? (string) $options['wrapper_class']
                : 'validakey-license-purchase',
            'payment_wrapper_id' => isset($options['payment_wrapper_id']) && '' !== (string) $options['payment_wrapper_id']
                ? (string) $options['payment_wrapper_id']
                : InstancePaymentPanel::DEFAULT_WRAPPER_ID,
            'heading' => \array_key_exists('heading', $options)
                ? (null === $options['heading'] ? null : (string) $options['heading'])
                : \__('Software license', 'validakey'),
            'intro' => \array_key_exists('intro', $options)
                ? (null === $options['intro'] ? null : (string) $options['intro'])
                : null,            'payment_heading' => \array_key_exists('payment_heading', $options)
                ? (null === $options['payment_heading'] ? null : (string) $options['payment_heading'])
                : \__('Payment method', 'validakey'),
            'payment_intro' => \array_key_exists('payment_intro', $options)
                ? (null === $options['payment_intro'] ? null : (string) $options['payment_intro'])
                : \__(
                    'There is a price for this license. Add payment details to purchase licensing through our pci-compliant payment processor.',
                    'validakey'
                ),
            'configured' => ! \array_key_exists('configured', $options) || (bool) $options['configured'],
            'unconfigured_message' => isset($options['unconfigured_message']) && '' !== trim((string) $options['unconfigured_message'])
                ? (string) $options['unconfigured_message']
                : \__('Validakey is not configured. License-path constants (VALIDAKEY_BASE_URL, VALIDAKEY_API_UUID, VALIDAKEY_USER_APP_ID) must ship with the plugin.', 'validakey'),
            'echo' => ! isset($options['echo']) || (bool) $options['echo'],
        );
    }

    /**
     * @return array{policy: ?MintPolicyResponse, error: ?string}
     */
    private static function loadPolicy(License $license): array
    {
        try {
            return array(
                'policy' => $license->mintPolicy(),
                'error' => null,
            );
        } catch (ApiException $e) {
            if (404 === $e->httpStatus) {
                return array(
                    'policy' => null,
                    'error' => 'policy_endpoint_missing',
                );
            }

            return array(
                'policy' => null,
                'error' => $e->getMessage(),
            );
        } catch (\Throwable $e) {
            return array(
                'policy' => null,
                'error' => $e->getMessage(),
            );
        }
    }

    private static function renderPolicySummary(?MintPolicyResponse $policy, ?string $policyError = null): void
    {
        echo '<div class="validakey-mint-policy" style="margin:1em 0;">';
        echo '<h3 class="title">' . \esc_html(\__('License terms', 'validakey')) . '</h3>';

        if ('policy_endpoint_missing' === $policyError) {
            echo '<p>' . \esc_html(\__(
                'This API host does not expose mint policy (POST /v1/policy/ returned 404). Until that endpoint is deployed, the panel cannot show enforced terms and will treat requests as the plugin’s default token shape.',
                'validakey'
            )) . '</p></div>';

            return;
        }

        if (null !== $policyError && '' !== $policyError) {
            echo '<p class="validakey-license-error">' . \esc_html(\sprintf(
                /* translators: %s: API error message */
                \__('Could not load mint policy: %s', 'validakey'),
                $policyError
            )) . '</p></div>';

            return;
        }

        if (null === $policy || ! $policy->isEnabled()) {
            echo '<p>' . \esc_html(\__(
                'No enforced policy found for this app id. Requesting a license using the plugin’s defaults.',
                'validakey'
            )) . '</p></div>';

            return;
        }

        echo '<table class="widefat striped validakey-mint-policy-table" role="presentation"><tbody>';

        $basis = $policy->defaultBasisCents();
        $tax = $policy->defaultTaxCents();
        if (null !== $basis || null !== $tax) {
            $price = self::formatUsdFromCents((int) ($basis ?? 0));
            if (null !== $tax && $tax > 0) {
                $price .= ' + ' . self::formatUsdFromCents($tax) . ' ' . \__('tax', 'validakey');
            }
            self::policyRow(\__('Price', 'validakey'), $price);
            if ($policy->isFixedPrice()) {
                self::policyRow(\__('Pricing', 'validakey'), \__('Fixed', 'validakey'));
            }
        } else {
            self::policyRow(\__('Price', 'validakey'), \__('Free / unset in policy defaults', 'validakey'));
        }

        $duration = $policy->defaultDuration();
        if (null !== $duration) {
            self::policyRow(\__('Duration', 'validakey'), self::formatDuration($duration));
        } elseif ($policy->allowNoExpiry()) {
            self::policyRow(\__('Duration', 'validakey'), \__('Never expires', 'validakey'));
        }

        $uses = $policy->defaultUses();
        if (null !== $uses) {
            self::policyRow(\__('Uses', 'validakey'), (string) $uses);
        }

        echo '</tbody></table></div>';
    }

    private static function policyRow(string $label, string $value): void
    {
        echo '<tr><th scope="row">' . \esc_html($label) . '</th><td>' . \esc_html($value) . '</td></tr>';
    }

    private static function purchaseButtonLabel(?MintPolicyResponse $policy): string
    {
        if (self::policyLooksPriced($policy)) {
            return \__('Purchase license', 'validakey');
        }

        return \__('Request license', 'validakey');
    }

    private static function policyLooksPriced(?MintPolicyResponse $policy): bool
    {
        if (null === $policy || ! $policy->isEnabled()) {
            return false;
        }

        $basis = $policy->defaultBasisCents() ?? 0;
        $tax = $policy->defaultTaxCents() ?? 0;

        return $basis > 0 || $tax > 0;
    }

    private static function formatUsdFromCents(int $cents): string
    {
        return '$' . \number_format($cents / 100, 2, '.', ',');
    }

    private static function formatDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return \__('None', 'validakey');
        }
        if (0 === $seconds % 86400) {
            $days = (int) ($seconds / 86400);

            return \sprintf(
                /* translators: %d: number of days */
                \_n('%d day', '%d days', $days, 'validakey'),
                $days
            );
        }
        if (0 === $seconds % 3600) {
            $hours = (int) ($seconds / 3600);

            return \sprintf(
                /* translators: %d: number of hours */
                \_n('%d hour', '%d hours', $hours, 'validakey'),
                $hours
            );
        }

        return \sprintf(
            /* translators: %d: duration in seconds */
            \_n('%d second', '%d seconds', $seconds, 'validakey'),
            $seconds
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
