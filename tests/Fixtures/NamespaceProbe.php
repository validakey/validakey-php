<?php

declare(strict_types=1);

namespace Validakey\Tests\Fixtures;

use Validakey\WordPress\PluginClientFactory;

/**
 * Callable from tests so callerNamespace_Guess() sees this namespace.
 */
final class NamespaceProbe
{
    public static function guessNamespace(): ?string
    {
        return PluginClientFactory::callerNamespace_Guess();
    }

    public static function configFromGuessedNamespace(): array
    {
        return PluginClientFactory::configArrayFromConstants();
    }
}
