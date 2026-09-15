<?php

declare(strict_types=1);

namespace Validakey;

/**
 * Wire format for the Instance Token handshake.
 *
 * Every message is `prefix + hex(Feistel(plaintext))`, where the prefix is the
 * leading characters of the key id in cleartext and the full key id is the
 * cipher key. The receiver uses the prefix to look up a small set of candidate
 * key ids, then walks them against a few time buckets until one decrypts to a
 * plaintext that validates.
 *
 * Plaintext fields are fixed-width with no delimiters, deliberately: both ends
 * carry the same widths as constants, so an observer gets no structural hints.
 * Only the final field of any layout may be variable-width, which PKCS#7
 * unpadding recovers exactly.
 *
 * This class is mirrored byte-for-byte by vKey\Vkey_Envelope on the server.
 * Any change here must be made there too, and the parity fixtures regenerated.
 */
final class Envelope
{
    /** JSON body field that carries a sealed message in both directions. */
    public const WIRE_FIELD = 'q';

    /** Cleartext characters of the key id placed on the wire as a lookup index. */
    public const PREFIX_LEN = 8;

    /** Random bytes prefixed to every plaintext, so near-identical payloads do not produce near-identical ECB ciphertext. */
    public const NONCE_LEN = 8;

    /** Length of a UUIDv4 in string form. */
    public const UUID_LEN = 36;

    /** Zero-padded unix seconds, as carried in token requests. */
    public const TS_LEN = 10;

    /**
     * Largest end-customer subject, in bytes.
     *
     * 120 bytes pads to 128 under PKCS#7 and hex-encodes to 256 characters,
     * which is exactly the width of the column the server stores it in.
     */
    public const SUBJECT_MAX = 120;

    /** Zero-padded decimal width of the subject length prefix. */
    public const SUB_LEN_WIDTH = 3;

    /** Time quantization: 8 bits means the key rolls every 256 seconds. */
    public const TIME_BITS = 8;

    /** Buckets a receiver tries, in order, to absorb clock skew across a boundary. */
    public const BUCKET_SHIFTS = array(0, -1, 1);

    /** Where in the key id the per-id time offset is read from. */
    private const OFFSET_POS = 10;
    private const OFFSET_LEN = 2;
    private const OFFSET_SHIFT = 13;

    /**
     * The constant time displacement derived from a key id.
     *
     * Split out from modTime() because it is the only deterministic part, so it
     * can be asserted against the shared parity fixture. Drift in the offset
     * constants between the two implementations would otherwise only show up as
     * an unexplained handshake failure.
     */
    public static function timeOffset(string $keyId): int
    {
        if (strlen($keyId) < self::OFFSET_POS + self::OFFSET_LEN) {
            throw new \InvalidArgumentException('Key id is too short to derive a time offset.');
        }

        return (int) (hexdec(substr($keyId, self::OFFSET_POS, self::OFFSET_LEN)) << self::OFFSET_SHIFT);
    }

    /**
     * Quantized time, shifted by a constant derived from the key id itself.
     *
     * The offset is a pure function of the key id, so any party holding the id
     * computes the same value; an observer without it cannot tell how far the
     * reported time was displaced.
     */
    public static function modTime(string $keyId, int $bucketShift = 0, int $bits = self::TIME_BITS): int
    {
        $bucket = (time() >> $bits) + $bucketShift;

        return ($bucket << $bits) - self::timeOffset($keyId);
    }

    /**
     * The Feistel key for a given id and time bucket.
     */
    public static function keyFor(string $keyId, int $bucketShift = 0): string
    {
        return $keyId . dechex(self::modTime($keyId, $bucketShift));
    }

    /**
     * The cleartext lookup index for a key id.
     */
    public static function prefix(string $keyId): string
    {
        return substr($keyId, 0, self::PREFIX_LEN);
    }

    public static function nonce(): string
    {
        return random_bytes(self::NONCE_LEN);
    }

    /**
     * Encrypt a plaintext and prepend the cleartext lookup prefix.
     */
    public static function seal(string $plaintext, string $keyId, int $bucketShift = 0): string
    {
        $cipher = new ValidakeyFeistel(self::keyFor($keyId, $bucketShift));

        return self::prefix($keyId) . $cipher->encrypt($plaintext);
    }

    /**
     * Encrypt without a prefix, for replies where the peer already knows the key.
     */
    public static function sealBare(string $plaintext, string $keyId, int $bucketShift = 0): string
    {
        $cipher = new ValidakeyFeistel(self::keyFor($keyId, $bucketShift));

        return $cipher->encrypt($plaintext);
    }

    /**
     * Split a sealed message into its cleartext prefix and ciphertext.
     *
     * @return array{prefix: string, cipher: string}
     */
    public static function split(string $wire): array
    {
        if (strlen($wire) <= self::PREFIX_LEN) {
            throw new \InvalidArgumentException('Sealed message is too short to contain a prefix and ciphertext.');
        }

        return array(
            'prefix' => substr($wire, 0, self::PREFIX_LEN),
            'cipher' => substr($wire, self::PREFIX_LEN),
        );
    }

    /**
     * Decrypt with a known key id, trying each tolerated time bucket.
     *
     * @param callable(string):bool|null $validator Rejects plaintexts that decrypt but are not well-formed.
     */
    public static function openWith(string $cipherText, string $keyId, ?callable $validator = null): ?string
    {
        foreach (self::BUCKET_SHIFTS as $shift) {
            try {
                $plaintext = (new ValidakeyFeistel(self::keyFor($keyId, $shift)))->decrypt($cipherText);
            } catch (\Throwable $e) {
                continue;
            }

            if (null === $validator || $validator($plaintext)) {
                return $plaintext;
            }
        }

        return null;
    }

    /**
     * Walk candidate key ids until one decrypts to a plaintext the validator accepts.
     *
     * @param iterable<string>           $candidateIds Ids sharing the wire prefix.
     * @param callable(string,string):bool $validator  Receives (plaintext, keyId).
     * @return array{key_id: string, plaintext: string}|null
     */
    public static function open(string $cipherText, iterable $candidateIds, callable $validator): ?array
    {
        foreach ($candidateIds as $keyId) {
            foreach (self::BUCKET_SHIFTS as $shift) {
                try {
                    $plaintext = (new ValidakeyFeistel(self::keyFor($keyId, $shift)))->decrypt($cipherText);
                } catch (\Throwable $e) {
                    continue;
                }

                if ($validator($plaintext, $keyId)) {
                    return array(
                        'key_id' => $keyId,
                        'plaintext' => $plaintext,
                    );
                }
            }
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Layouts. Only the last field of each may be variable-width.
    // ---------------------------------------------------------------------

    /**
     * Handshake request: nonce(8) + uuid(36) + sub_len(3) + subject + fingerprint(rest).
     *
     * The subject names the end customer this instance belongs to, so one
     * machine running one application can hold a separate Instance Entity per
     * paying customer. It is length-prefixed rather than trailing because only
     * one field of a layout may be variable-width, and the fingerprint already
     * holds that position.
     *
     * An empty subject packs as `000`. This stays a pure codec and does not
     * generate an id; the handshake API requires a non-empty subject.
     * ValidakeyClient always supplies one before packing.
     */
    public static function packHandshake(string $uuid, string $fingerprint, string $subject = ''): string
    {
        if (self::UUID_LEN !== strlen($uuid)) {
            throw new \InvalidArgumentException('UUID must be exactly ' . self::UUID_LEN . ' characters.');
        }
        if ('' === $fingerprint) {
            throw new \InvalidArgumentException('Fingerprint must not be empty.');
        }

        /*
         * Bytes, not characters. The server seals the subject with PKCS#7
         * padding to 8-byte blocks and stores it hex-encoded, so 120 bytes is
         * what fills its column exactly. Counting characters would let a
         * multibyte subject overflow it.
         */
        if (strlen($subject) > self::SUBJECT_MAX) {
            throw new \InvalidArgumentException('Subject must be at most ' . self::SUBJECT_MAX . ' bytes.');
        }

        $subLen = str_pad((string) strlen($subject), self::SUB_LEN_WIDTH, '0', STR_PAD_LEFT);

        return self::nonce() . $uuid . $subLen . $subject . $fingerprint;
    }

    /**
     * @return array{nonce: string, nonce_hex: string, uuid: string, subject: string, fingerprint: string}|null
     */
    public static function unpackHandshake(string $plaintext): ?array
    {
        $head = self::NONCE_LEN + self::UUID_LEN + self::SUB_LEN_WIDTH;
        if (strlen($plaintext) <= $head) {
            return null;
        }

        $uuid = substr($plaintext, self::NONCE_LEN, self::UUID_LEN);
        if (1 !== preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $uuid)) {
            return null;
        }

        $subLen = substr($plaintext, self::NONCE_LEN + self::UUID_LEN, self::SUB_LEN_WIDTH);
        if (1 !== preg_match('/^[0-9]{' . self::SUB_LEN_WIDTH . '}$/', $subLen)) {
            return null;
        }
        $subLen = (int) $subLen;
        if ($subLen > self::SUBJECT_MAX) {
            return null;
        }

        $subject = $subLen > 0 ? substr($plaintext, $head, $subLen) : '';
        $fingerprint = substr($plaintext, $head + $subLen);
        if (strlen($subject) !== $subLen) {
            return null;
        }
        if ('' === $fingerprint || strlen($fingerprint) > 255) {
            return null;
        }

        $nonce = substr($plaintext, 0, self::NONCE_LEN);

        return array(
            'nonce' => $nonce,
            'nonce_hex' => bin2hex($nonce),
            'uuid' => $uuid,
            'subject' => $subject,
            'fingerprint' => $fingerprint,
        );
    }

    /**
     * Handshake reply: nonce(8) + instanceId(36).
     */
    public static function packInstance(string $instanceId): string
    {
        if (self::UUID_LEN !== strlen($instanceId)) {
            throw new \InvalidArgumentException('Instance id must be exactly ' . self::UUID_LEN . ' characters.');
        }

        return self::nonce() . $instanceId;
    }

    public static function unpackInstance(string $plaintext): ?string
    {
        if (self::NONCE_LEN + self::UUID_LEN !== strlen($plaintext)) {
            return null;
        }

        $instanceId = substr($plaintext, self::NONCE_LEN, self::UUID_LEN);

        return 1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $instanceId)
            ? $instanceId
            : null;
    }

    /**
     * Token request: nonce(8) + ts(10) + json(rest).
     *
     * The nonce is echoed to the server for replay rejection; the timestamp is
     * checked against the server clock independently of the bucket used.
     *
     * @param array<string, mixed> $payload
     */
    public static function packRequest(array $payload, ?int $timestamp = null): string
    {
        $timestamp = $timestamp ?? time();

        return self::nonce()
            . str_pad((string) $timestamp, self::TS_LEN, '0', STR_PAD_LEFT)
            . (string) json_encode($payload);
    }

    /**
     * @return array{nonce: string, nonce_hex: string, ts: int, payload: array<string, mixed>}|null
     */
    public static function unpackRequest(string $plaintext): ?array
    {
        $head = self::NONCE_LEN + self::TS_LEN;
        if (strlen($plaintext) <= $head) {
            return null;
        }

        $ts = substr($plaintext, self::NONCE_LEN, self::TS_LEN);
        if (1 !== preg_match('/^[0-9]{' . self::TS_LEN . '}$/', $ts)) {
            return null;
        }

        $payload = json_decode(substr($plaintext, $head), true);
        if (! is_array($payload)) {
            return null;
        }

        $nonce = substr($plaintext, 0, self::NONCE_LEN);

        return array(
            'nonce' => $nonce,
            'nonce_hex' => bin2hex($nonce),
            'ts' => (int) $ts,
            'payload' => $payload,
        );
    }

    /**
     * Token reply: nonce(8) + json(rest).
     *
     * @param array<string, mixed> $payload
     */
    public static function packReply(array $payload): string
    {
        return self::nonce() . (string) json_encode($payload);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function unpackReply(string $plaintext): ?array
    {
        if (strlen($plaintext) <= self::NONCE_LEN) {
            return null;
        }

        $payload = json_decode(substr($plaintext, self::NONCE_LEN), true);

        return is_array($payload) ? $payload : null;
    }
}
