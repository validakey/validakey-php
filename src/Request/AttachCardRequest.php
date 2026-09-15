<?php

declare(strict_types=1);

namespace Validakey\Request;

final class AttachCardRequest
{
    /**
     * @param array<string, string> $billingFields
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly array $billingFields = array(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(
            array('source_id' => $this->sourceId),
            $this->billingFields
        );
    }
}
