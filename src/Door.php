<?php

declare(strict_types=1);

namespace Validakey;

/**
 * Client-side Dynamic Pass helpers: pack a rotating /g/ redeem code from a
 * per-vKey door_seed. Algorithm matches the server wire format (HMAC-SHA256,
 * 60s buckets, 48-bit MAC) and does not follow v1_0_cipher.hash_algo.
 *
 * QR / redeem URL = Token locator prefixes + truncated HMAC of door_seed for
 * the current time bucket, packed as 19-char unpadded base64url. Redeem is a
 * public GET /g/{packed}/ (not an instance-sealed /v1/ route).
 *
 * Never put door_seed or the full vKey in HTML or email. Keep the seed
 * server-side and regenerate the packed URL as buckets roll.
 */
final class Door
{
    public const DOMAIN = 'vkey-g';
    public const BUCKET_SECS = 60;
    public const MAC_BYTES = 6;
    public const PREFIX_BYTES = 4;
    public const PACKED_BYTES = 14;
    public const PACKED_CHARS = 19;
    public const SCANNER_PREFIX = 'vk_g_';

    /**
     * Time bucket for Dynamic Pass MACs.
     */
    public static function bucket(int $unix = 0): int
    {
        $unix = $unix > 0 ? $unix : time();

        return (int) floor($unix / self::BUCKET_SECS);
    }

    /**
     * Truncated HMAC-SHA256(door_seed, "vkey-g" || bucket) — 6 raw bytes.
     */
    public static function mac48(string $seedBytes, int $bucket): string
    {
        $raw = hash_hmac('sha256', self::DOMAIN . pack('J', $bucket), $seedBytes, true);

        return substr($raw, 0, self::MAC_BYTES);
    }

    /**
     * Whether a presented MAC matches the seed for bucket n, n-1, or n+1.
     */
    public static function macMatches(string $seedBytes, string $mac, int $now = 0): bool
    {
        if (8 !== strlen($seedBytes) || self::MAC_BYTES !== strlen($mac)) {
            return false;
        }

        $n = self::bucket($now);
        $match = false;
        foreach (array($n - 1, $n, $n + 1) as $b) {
            if (hash_equals(self::mac48($seedBytes, $b), $mac)) {
                $match = true;
            }
        }

        return $match;
    }

    /**
     * 8-byte seed from a 16-char hex door_seed, or empty on junk.
     */
    public static function hexToSeedBytes(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (16 !== strlen($hex) || ! ctype_xdigit($hex)) {
            return '';
        }
        $bytes = @hex2bin($hex);

        return is_string($bytes) && 8 === strlen($bytes) ? $bytes : '';
    }

    /**
     * 16-char lowercase hex from 8 seed bytes.
     */
    public static function seedBytesToHex(string $bytes): string
    {
        return strtolower(bin2hex($bytes));
    }

    /**
     * 8-char hex prefix → 4 bytes, or empty.
     */
    public static function prefixToBytes(string $hex): string
    {
        $hex = strtolower(trim($hex));
        if (8 !== strlen($hex) || ! ctype_xdigit($hex)) {
            return '';
        }
        $bytes = @hex2bin($hex);

        return is_string($bytes) && 4 === strlen($bytes) ? $bytes : '';
    }

    /**
     * 4 bytes → 8-char lowercase hex.
     */
    public static function bytesToPrefix(string $bytes): string
    {
        return strtolower(bin2hex($bytes));
    }

    /**
     * Unpadded base64url of locator_bits || mac48 (19 chars), or empty on bad input.
     */
    public static function packToken(string $instancePre, string $tokenPre, string $mac): string
    {
        $i = self::prefixToBytes($instancePre);
        $t = self::prefixToBytes($tokenPre);
        if ('' === $i || '' === $t || self::MAC_BYTES !== strlen($mac)) {
            return '';
        }

        return self::b64url($i . $t . $mac);
    }

    /**
     * @return array{instance_pre: string, token_pre: string, mac: string}|null
     */
    public static function unpackToken(string $packed): ?array
    {
        $packed = trim($packed);
        if (self::PACKED_CHARS !== strlen($packed)) {
            return null;
        }
        $raw = self::b64urlDecode($packed);
        if (self::PACKED_BYTES !== strlen($raw)) {
            return null;
        }

        return array(
            'instance_pre' => self::bytesToPrefix(substr($raw, 0, 4)),
            'token_pre' => self::bytesToPrefix(substr($raw, 4, 4)),
            'mac' => substr($raw, 8, 6),
        );
    }

    /**
     * Pack a live Dynamic Pass for the current (or given) time bucket.
     *
     * @param string $instancePre 8-char hex IE prefix
     * @param string $tokenPre    8-char hex vKey prefix
     * @param string $doorSeedHex 16-char hex door_seed from mint
     */
    public static function packLive(
        string $instancePre,
        string $tokenPre,
        string $doorSeedHex,
        ?int $unix = null,
    ): string {
        $seed = self::hexToSeedBytes($doorSeedHex);
        if ('' === $seed) {
            return '';
        }

        $bucket = self::bucket(null === $unix ? 0 : $unix);

        return self::packToken($instancePre, $tokenPre, self::mac48($seed, $bucket));
    }

    /**
     * Build the public redeem URL for a packed Dynamic Pass.
     *
     * Accepts a client base such as https://api.validakey.com/v1 and strips a
     * trailing /v1 so the path is {origin}/g/{packed}/.
     */
    public static function redeemUrl(string $apiBaseUrl, string $packed): string
    {
        $packed = trim($packed);
        if (self::PACKED_CHARS !== strlen($packed)) {
            return '';
        }

        $base = rtrim(trim($apiBaseUrl), '/');
        if (str_ends_with(strtolower($base), '/v1')) {
            $base = substr($base, 0, -3);
            $base = rtrim($base, '/');
        }

        return $base . '/g/' . $packed . '/';
    }

    /**
     * Pack + redeem URL in one call for the current bucket.
     */
    public static function liveRedeemUrl(
        string $apiBaseUrl,
        string $instancePre,
        string $tokenPre,
        string $doorSeedHex,
        ?int $unix = null,
    ): string {
        $packed = self::packLive($instancePre, $tokenPre, $doorSeedHex, $unix);
        if ('' === $packed) {
            return '';
        }

        return self::redeemUrl($apiBaseUrl, $packed);
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64urlDecode(string $text): string
    {
        $pad = strlen($text) % 4;
        if ($pad) {
            $text .= str_repeat('=', 4 - $pad);
        }
        $out = base64_decode(strtr($text, '-_', '+/'), true);

        return is_string($out) ? $out : '';
    }
}
