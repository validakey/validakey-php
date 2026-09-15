<?php

declare(strict_types=1);

namespace Validakey\WordPress;

/**
 * Schedules periodic {@see \Validakey\License::revalidate()} via WP-Cron.
 */
final class LicenseScheduler
{
    public const DEFAULT_RECURRENCE = 'twicedaily';

    /**
     * Ensure a recurring event exists for $hook.
     *
     * @param callable(): void $callback Invoked when the hook fires
     */
    public static function schedule(string $hook, callable $callback, string $recurrence = self::DEFAULT_RECURRENCE): void
    {
        if (! \function_exists('wp_next_scheduled') || ! \function_exists('wp_schedule_event')) {
            return;
        }

        \add_action($hook, $callback);

        if (false === \wp_next_scheduled($hook)) {
            \wp_schedule_event(time() + 60, $recurrence, $hook);
        }
    }

    /**
     * Clear the recurring event and remove the action callback.
     */
    public static function unschedule(string $hook): void
    {
        if (\function_exists('wp_next_scheduled') && \function_exists('wp_unschedule_event')) {
            $timestamp = \wp_next_scheduled($hook);
            while (false !== $timestamp) {
                \wp_unschedule_event($timestamp, $hook);
                $timestamp = \wp_next_scheduled($hook);
            }
        }

        if (\function_exists('remove_all_actions')) {
            \remove_all_actions($hook);
        }
    }

    /**
     * Cron hook name for a plugin slug.
     */
    public static function hookForSlug(string $pluginSlug): string
    {
        $pluginSlug = trim($pluginSlug);
        if ('' === $pluginSlug) {
            throw new \InvalidArgumentException('pluginSlug must not be empty.');
        }

        return $pluginSlug . '_validakey_revalidate';
    }
}
