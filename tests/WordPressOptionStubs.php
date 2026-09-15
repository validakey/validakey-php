<?php

declare(strict_types=1);

/**
 * Minimal WordPress option stubs for unit tests (no WP bootstrap).
 *
 * @var array<string, mixed> $validakey_test_wp_options
 */
$GLOBALS['validakey_test_wp_options'] = array();

if (! function_exists('get_option')) {
    /**
     * @param mixed $default
     * @return mixed
     */
    function get_option(string $option, $default = false)
    {
        if (! array_key_exists($option, $GLOBALS['validakey_test_wp_options'])) {
            return $default;
        }

        return $GLOBALS['validakey_test_wp_options'][$option];
    }
}

if (! function_exists('update_option')) {
    /**
     * @param mixed $value
     */
    function update_option(string $option, $value, mixed $autoload = null): bool
    {
        $GLOBALS['validakey_test_wp_options'][$option] = $value;
        $GLOBALS['validakey_test_wp_update_autoload'][$option] = $autoload;

        return true;
    }
}

if (! function_exists('home_url')) {
    function home_url(string $path = '', ?string $scheme = null): string
    {
        return $GLOBALS['validakey_test_home_url'] ?? 'https://shop.example.com';
    }
}

if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

if (! function_exists('esc_html')) {
    function esc_html(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('esc_attr')) {
    function esc_attr(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (! function_exists('is_admin')) {
    function is_admin(): bool
    {
        return (bool) ($GLOBALS['validakey_test_is_admin'] ?? true);
    }
}

if (! function_exists('current_user_can')) {
    function current_user_can(string $capability): bool
    {
        $caps = $GLOBALS['validakey_test_caps'] ?? array('manage_options');

        return in_array($capability, $caps, true);
    }
}

if (! function_exists('wp_nonce_field')) {
    function wp_nonce_field(string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true): string
    {
        $html = '<input type="hidden" name="' . esc_attr($name) . '" value="test-nonce" />';
        if ($display) {
            echo $html;
        }

        return $html;
    }
}

if (! function_exists('submit_button')) {
    /**
     * @param string|array<string, string> $other_attributes
     */
    function submit_button(
        string $text = 'Save Changes',
        string $type = 'primary',
        string $name = 'submit',
        bool $wrap = true,
        $other_attributes = null
    ): void {
        echo '<input type="submit" name="' . esc_attr($name) . '" value="' . esc_attr($text) . '" class="button button-' . esc_attr($type) . '" />';
    }
}

if (! function_exists('get_transient')) {
    /**
     * @return mixed
     */
    function get_transient(string $transient)
    {
        return $GLOBALS['validakey_test_transients'][$transient] ?? false;
    }
}

if (! function_exists('set_transient')) {
    function set_transient(string $transient, mixed $value, int $expiration = 0): bool
    {
        $GLOBALS['validakey_test_transients'][$transient] = $value;

        return true;
    }
}

if (! function_exists('delete_transient')) {
    function delete_transient(string $transient): bool
    {
        unset($GLOBALS['validakey_test_transients'][$transient]);

        return true;
    }
}

if (! function_exists('wp_parse_url')) {
    /**
     * @return mixed
     */
    function wp_parse_url(string $url, int $component = -1)
    {
        return parse_url($url, $component);
    }
}

if (! function_exists('sanitize_key')) {
    function sanitize_key(string $key): string
    {
        return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '');
    }
}

if (! function_exists('wp_unslash')) {
    /**
     * @param mixed $value
     * @return mixed
     */
    function wp_unslash($value)
    {
        return $value;
    }
}

if (! function_exists('add_action')) {
    function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): void
    {
        $GLOBALS['validakey_test_actions'][$hook][] = $callback;
    }
}

if (! function_exists('remove_action')) {
    function remove_action(string $hook, mixed $callback, int $priority = 10): bool
    {
        if (! isset($GLOBALS['validakey_test_actions'][$hook])) {
            return false;
        }

        $GLOBALS['validakey_test_actions'][$hook] = array_values(array_filter(
            $GLOBALS['validakey_test_actions'][$hook],
            static fn ($item) => $item !== $callback
        ));

        return true;
    }
}

if (! function_exists('remove_all_actions')) {
    function remove_all_actions(string $hook, false|int $priority = false): void
    {
        unset($GLOBALS['validakey_test_actions'][$hook]);
    }
}

if (! function_exists('wp_next_scheduled')) {
    /**
     * @return int|false
     */
    function wp_next_scheduled(string $hook, array $args = array())
    {
        return $GLOBALS['validakey_test_cron'][$hook] ?? false;
    }
}

if (! function_exists('wp_schedule_event')) {
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = array(), bool $wp_error = false): bool
    {
        $GLOBALS['validakey_test_cron'][$hook] = $timestamp;
        $GLOBALS['validakey_test_cron_recurrence'][$hook] = $recurrence;

        return true;
    }
}

if (! function_exists('wp_unschedule_event')) {
    function wp_unschedule_event(int $timestamp, string $hook, array $args = array(), bool $wp_error = false): bool
    {
        unset($GLOBALS['validakey_test_cron'][$hook], $GLOBALS['validakey_test_cron_recurrence'][$hook]);

        return true;
    }
}

if (! function_exists('admin_url')) {
    function admin_url(string $path = '', ?string $scheme = null): string
    {
        return 'https://shop.example.com/wp-admin/' . ltrim($path, '/');
    }
}

if (! function_exists('add_submenu_page')) {
    function add_submenu_page(
        string $parent_slug,
        string $page_title,
        string $menu_title,
        string $capability,
        string $menu_slug,
        mixed $callback = '',
        int|float $position = null
    ): string {
        $GLOBALS['validakey_test_submenus'][] = compact(
            'parent_slug',
            'page_title',
            'menu_title',
            'capability',
            'menu_slug',
            'callback'
        );

        return $menu_slug;
    }
}

