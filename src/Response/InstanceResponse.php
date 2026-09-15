<?php

declare(strict_types=1);

namespace Validakey\Response;

/**
 * The result of an instance handshake.
 *
 * The instance id arrives encrypted, so unlike the other responses this is not
 * built from the JSON body; ValidakeyClient constructs it after opening the
 * reply envelope.
 */
final class InstanceResponse
{
    public function __construct(
        public readonly string $instanceId,
        public readonly string $fingerprint,
        public readonly string $subject = '',
        public readonly ?bool $machineLocked = null,
        public readonly ?bool $transferEligible = null,
        public readonly ?string $transferExpires = null,
        public readonly ?int $machineChanges = null,
        public readonly ?int $subjectTransfers = null,
    ) {
    }
}
