<?php

declare(strict_types=1);

namespace Validakey;

/**
 * Identifies which client application and host initiated a Validakey API call.
 *
 * These fields travel in cleartext, so they are only attached to the
 * Bearer-authenticated account endpoints, where the caller has already proven
 * who it is. The token path carries the same identity inside the encrypted
 * handshake envelope instead; see Validakey\Envelope.
 */
final class ApplicationContext
{
    public const HEADER_USER_APP_ID = 'X-Validakey-User-App-Id';
    public const HEADER_HOST = 'X-Validakey-Host';
    public const HEADER_USER_ID = 'X-Validakey-User-Id';

    public readonly string $host;

    public function __construct(
        public readonly string $userAppId,
        public readonly string $apiUUID,
        ?string $host = null,
    ) {
        if ('' === trim($this->userAppId)) {
            throw new \InvalidArgumentException('userAppId must not be empty.');
        }
        if ('' === trim($this->apiUUID)) {
            throw new \InvalidArgumentException('apiUUID must not be empty.');
        }

        $this->host = self::resolveHost($host);
    }

    /**
     * Resolve a host name for the current environment.
     */
    public static function resolveHost(?string $host = null): string
    {
        if (null !== $host && '' !== trim($host)) {
            return trim($host);
        }

        if (isset($_SERVER['HTTP_HOST']) && '' !== (string) $_SERVER['HTTP_HOST']) {
            return trim((string) $_SERVER['HTTP_HOST']);
        }

        if (isset($_SERVER['SERVER_NAME']) && '' !== (string) $_SERVER['SERVER_NAME']) {
            return trim((string) $_SERVER['SERVER_NAME']);
        }

        $hostname = gethostname();

        return (false === $hostname || '' === $hostname) ? 'unknown' : $hostname;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return array(
            self::HEADER_USER_APP_ID => $this->userAppId,
            self::HEADER_HOST => $this->host,
            self::HEADER_USER_ID => $this->apiUUID,
        );
    }

    /**
     * Fields merged into JSON request bodies.
     *
     * @return array<string, string>
     */
    public function bodyFields(): array
    {
        return array(
            'user_app_id' => $this->userAppId,
            'host' => $this->host,
            'api_user_id' => $this->apiUUID,
        );
    }

    /**
     * Query string parameters for GET requests.
     *
     * @return array<string, string>
     */
    public function queryParams(): array
    {
        return $this->bodyFields();
    }
}
