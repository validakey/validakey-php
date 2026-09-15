<?php

declare(strict_types=1);

namespace Validakey;

final class ValidakeyConfig
{
    /**
     * @param string      $baseUrl   Validakey API root, e.g. https://validakey.com/api/v1
     * @param string      $apiUUID   The Validakey account UUID.
     * @param string      $userAppId Client-assigned id for this application or plugin.
     * @param string|null $apiPKey   The account private key. Only the
     *                               Bearer-authenticated account endpoints need
     *                               it; the token path is authenticated by the
     *                               instance handshake instead. Never ship it in
     *                               client-side code or commit it to a public
     *                               repository.
     * @param string|null $host      Overrides the auto-detected host name.
     */
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $apiUUID,
        public readonly string $userAppId,
        public readonly ?string $apiPKey = null,
        public readonly ?string $subject = null,
        public readonly ?string $host = null,
        public readonly float $timeout = 30.0,
    ) {
        if ('' === trim($this->baseUrl)) {
            throw new \InvalidArgumentException('baseUrl must not be empty.');
        }
        if ('' === trim($this->apiUUID)) {
            throw new \InvalidArgumentException('apiUUID must not be empty.');
        }
        if ('' === trim($this->userAppId)) {
            throw new \InvalidArgumentException('userAppId must not be empty.');
        }
    }

    public static function fromEnv(?string $prefix = 'VALIDAKEY_'): self
    {
        $prefix = $prefix ?? 'VALIDAKEY_';

        $apiPKey = getenv($prefix . 'API_PKEY') ?: '';
        $subject = getenv($prefix . 'SUBJECT') ?: '';
        $host = getenv($prefix . 'HOST') ?: '';
        $timeout = getenv($prefix . 'TIMEOUT') ?: '';

        return new self(
            baseUrl: getenv($prefix . 'BASE_URL') ?: '',
            apiUUID: getenv($prefix . 'API_UUID') ?: '',
            userAppId: getenv($prefix . 'USER_APP_ID') ?: '',
            apiPKey: '' === $apiPKey ? null : $apiPKey,
            subject: '' === $subject ? null : $subject,
            host: '' === $host ? null : $host,
            timeout: '' === $timeout ? 30.0 : (float) $timeout,
        );
    }

    /**
     * Whether the Bearer-authenticated account endpoints are usable.
     */
    public function hasPKey(): bool
    {
        return null !== $this->apiPKey && '' !== trim($this->apiPKey);
    }

    public function normalizedBaseUrl(): string
    {
        return rtrim($this->baseUrl, '/');
    }

    public function applicationContext(): ApplicationContext
    {
        return new ApplicationContext(
            userAppId: $this->userAppId,
            apiUUID: $this->apiUUID,
            host: ApplicationContext::resolveHost($this->host),
        );
    }

    public function withSubject(?string $subject): self
    {
        $subject = null === $subject ? null : trim($subject);

        return new self(
            baseUrl: $this->baseUrl,
            apiUUID: $this->apiUUID,
            userAppId: $this->userAppId,
            apiPKey: $this->apiPKey,
            subject: '' === $subject ? null : $subject,
            host: $this->host,
            timeout: $this->timeout,
        );
    }
}
