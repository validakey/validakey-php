<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Validakey\Envelope;

/**
 * Protocol-level tests for the handshake envelope.
 *
 * The fixture-backed cases guard against drift from the server implementation:
 * field widths, the time-offset derivation and the bucket list all have to
 * match, or the handshake fails to decrypt without saying why.
 */
final class EnvelopeTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/feistel-vectors.json');

        return json_decode((string) $raw, true)['envelope'];
    }

    public function testConstantsMatchFixture(): void
    {
        $f = self::fixture();

        self::assertSame($f['prefix_len'], Envelope::PREFIX_LEN);
        self::assertSame($f['nonce_len'], Envelope::NONCE_LEN);
        self::assertSame($f['uuid_len'], Envelope::UUID_LEN);
        self::assertSame($f['ts_len'], Envelope::TS_LEN);
        self::assertSame($f['subject_max'], Envelope::SUBJECT_MAX);
        self::assertSame($f['sub_len_width'], Envelope::SUB_LEN_WIDTH);
        self::assertSame($f['time_bits'], Envelope::TIME_BITS);
        self::assertSame($f['bucket_shifts'], Envelope::BUCKET_SHIFTS);
    }

    /**
     * @return array<string, array{keyId: string, offset: int}>
     */
    public static function offsetProvider(): array
    {
        $cases = array();
        foreach (self::fixture()['time_offsets'] as $entry) {
            $cases[$entry['key_id']] = array(
                'keyId' => $entry['key_id'],
                'offset' => $entry['offset'],
            );
        }

        return $cases;
    }

    #[DataProvider('offsetProvider')]
    public function testTimeOffsetMatchesFixture(string $keyId, int $offset): void
    {
        self::assertSame($offset, Envelope::timeOffset($keyId));
    }

    public function testTimeOffsetRejectsShortKeyId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Envelope::timeOffset('tooshort');
    }

    public function testModTimeIsQuantizedAndOffset(): void
    {
        $keyId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $expected = ((time() >> Envelope::TIME_BITS) << Envelope::TIME_BITS) - 2088960;

        self::assertSame($expected, Envelope::modTime($keyId));
    }

    public function testModTimeBucketShiftMovesOneQuantum(): void
    {
        $keyId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';

        self::assertSame(
            Envelope::modTime($keyId) - (1 << Envelope::TIME_BITS),
            Envelope::modTime($keyId, -1)
        );
    }

    public function testSealPrependsCleartextPrefixOnly(): void
    {
        $keyId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';
        $wire = Envelope::seal('payload here', $keyId);

        self::assertSame('b3f1c2d4', Envelope::split($wire)['prefix']);
        self::assertStringNotContainsString($keyId, $wire, 'Full key id must never appear on the wire.');
    }

    public function testHandshakeRoundTrip(): void
    {
        $appId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';
        $uuid = 'a1b2c3d4-5555-4666-8777-888899990000';
        $fingerprint = 'abc123_host.example.com';

        $wire = Envelope::seal(Envelope::packHandshake($uuid, $fingerprint), $appId);
        $parts = Envelope::split($wire);

        $opened = Envelope::open($parts['cipher'], array($appId), static function (string $plaintext): bool {
            return null !== Envelope::unpackHandshake($plaintext);
        });

        self::assertNotNull($opened);
        $unpacked = Envelope::unpackHandshake($opened['plaintext']);
        self::assertSame($uuid, $unpacked['uuid']);
        self::assertSame('', $unpacked['subject'], 'An omitted subject round trips as empty.');
        self::assertSame($fingerprint, $unpacked['fingerprint']);
    }

    public function testHandshakeRoundTripCarriesSubject(): void
    {
        $appId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';
        $uuid = 'a1b2c3d4-5555-4666-8777-888899990000';
        $fingerprint = 'abc123_host.example.com';
        $subject = 'cust_ac_9931';

        $wire = Envelope::seal(Envelope::packHandshake($uuid, $fingerprint, $subject), $appId);

        self::assertStringNotContainsString(
            $subject,
            $wire,
            'The subject must never appear in cleartext on the wire.'
        );

        $opened = Envelope::open(Envelope::split($wire)['cipher'], array($appId), static function (string $plaintext): bool {
            return null !== Envelope::unpackHandshake($plaintext);
        });

        self::assertNotNull($opened);
        $unpacked = Envelope::unpackHandshake($opened['plaintext']);
        self::assertSame($subject, $unpacked['subject']);
        self::assertSame(
            $fingerprint,
            $unpacked['fingerprint'],
            'A length-prefixed subject must not disturb the trailing fingerprint.'
        );
    }

    public function testSubjectCapIsCountedInBytes(): void
    {
        $uuid = 'a1b2c3d4-5555-4666-8777-888899990000';
        $fingerprint = 'abc123_host.example.com';

        $atLimit = Envelope::unpackHandshake(
            Envelope::packHandshake($uuid, $fingerprint, str_repeat('x', Envelope::SUBJECT_MAX))
        );
        self::assertNotNull($atLimit);
        self::assertSame(Envelope::SUBJECT_MAX, strlen($atLimit['subject']));

        $this->expectException(\InvalidArgumentException::class);
        Envelope::packHandshake($uuid, $fingerprint, str_repeat('x', Envelope::SUBJECT_MAX + 1));
    }

    public function testOpenRejectsWrongCandidateKey(): void
    {
        $appId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';
        $wrong = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000';

        $wire = Envelope::seal(
            Envelope::packHandshake('a1b2c3d4-5555-4666-8777-888899990000', 'fp_host'),
            $appId
        );

        $opened = Envelope::open(Envelope::split($wire)['cipher'], array($wrong), static function (string $plaintext): bool {
            return null !== Envelope::unpackHandshake($plaintext);
        });

        self::assertNull($opened);
    }

    public function testOpenPicksCorrectKeyFromCandidateSet(): void
    {
        $appId = '12345678-9abc-4def-8123-456789abcdef';
        $candidates = array(
            '00000000-0000-4000-8000-000000000000',
            'aaaaaaaa-bbbb-4ccc-8ddd-eeeeffff0000',
            $appId,
        );

        $wire = Envelope::seal(
            Envelope::packHandshake('a1b2c3d4-5555-4666-8777-888899990000', 'fp_host'),
            $appId
        );

        $opened = Envelope::open(Envelope::split($wire)['cipher'], $candidates, static function (string $plaintext): bool {
            return null !== Envelope::unpackHandshake($plaintext);
        });

        self::assertNotNull($opened);
        self::assertSame($appId, $opened['key_id']);
    }

    public function testInstanceReplyRoundTrip(): void
    {
        $appId = 'b3f1c2d4-1111-4aaa-8bbb-ccccddddeeee';
        $instanceId = 'deadbeef-1234-4567-89ab-cdef01234567';

        $reply = Envelope::sealBare(Envelope::packInstance($instanceId), $appId);
        $plaintext = Envelope::openWith($reply, $appId);

        self::assertNotNull($plaintext);
        self::assertSame($instanceId, Envelope::unpackInstance($plaintext));
    }

    public function testTokenRequestRoundTripCarriesNonceAndTimestamp(): void
    {
        $instanceId = 'deadbeef-1234-4567-89ab-cdef01234567';

        $wire = Envelope::seal(Envelope::packRequest(array('duration' => 3600, 'basis_cents' => 999)), $instanceId);
        $plaintext = Envelope::openWith(Envelope::split($wire)['cipher'], $instanceId);

        self::assertNotNull($plaintext);
        $unpacked = Envelope::unpackRequest($plaintext);
        self::assertSame(3600, $unpacked['payload']['duration']);
        self::assertSame(999, $unpacked['payload']['basis_cents']);
        self::assertLessThan(5, abs($unpacked['ts'] - time()));
        self::assertSame(16, strlen($unpacked['nonce_hex']));
    }

    public function testNonceDiffersBetweenIdenticalPayloads(): void
    {
        $instanceId = 'deadbeef-1234-4567-89ab-cdef01234567';

        $first = Envelope::seal(Envelope::packRequest(array('duration' => 3600)), $instanceId);
        $second = Envelope::seal(Envelope::packRequest(array('duration' => 3600)), $instanceId);

        self::assertNotSame($first, $second, 'Identical payloads must not produce identical ciphertext.');
    }

    public function testUnpackHandshakeRejectsMalformedPayloads(): void
    {
        $uuid = 'a1b2c3d4-5555-4666-8777-888899990000';
        $nonce = str_repeat("\x00", 8);

        self::assertNull(Envelope::unpackHandshake(''), 'empty');
        self::assertNull(Envelope::unpackHandshake(str_repeat('x', 40)), 'too short for uuid + fingerprint');
        self::assertNull(
            Envelope::unpackHandshake($nonce . str_repeat('z', 36) . '000fp'),
            'uuid field is not a uuid'
        );
        self::assertNull(
            Envelope::unpackHandshake($nonce . $uuid . '000'),
            'missing fingerprint'
        );
        self::assertNull(
            Envelope::unpackHandshake($nonce . $uuid . 'abcfp'),
            'subject length is not decimal'
        );
        self::assertNull(
            Envelope::unpackHandshake($nonce . $uuid . '999' . 'short'),
            'subject length overruns the payload'
        );
        self::assertNull(
            Envelope::unpackHandshake($nonce . $uuid . '012' . 'cust_ac_9931'),
            'a subject that consumes the whole tail leaves no fingerprint'
        );
    }

    /**
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function layoutProvider(): array
    {
        $cases = array();
        foreach (self::fixture()['layout_unpack'] as $entry) {
            $cases[$entry['name']] = array($entry['plaintext_hex'], $entry['expect']);
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $expect
     */
    #[DataProvider('layoutProvider')]
    public function testLayoutUnpackMatchesFixture(string $plaintextHex, array $expect): void
    {
        $plaintext = (string) hex2bin($plaintextHex);

        if (isset($expect['fingerprint'])) {
            $unpacked = Envelope::unpackHandshake($plaintext);
            self::assertSame($expect['uuid'], $unpacked['uuid']);
            self::assertSame($expect['subject'], $unpacked['subject']);
            self::assertSame($expect['fingerprint'], $unpacked['fingerprint']);

            return;
        }

        if (isset($expect['instance_id'])) {
            self::assertSame($expect['instance_id'], Envelope::unpackInstance($plaintext));

            return;
        }

        $unpacked = Envelope::unpackRequest($plaintext);
        self::assertSame($expect['ts'], $unpacked['ts']);
        self::assertSame($expect['payload'], $unpacked['payload']);
    }
}
