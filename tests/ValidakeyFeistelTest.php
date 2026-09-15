<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Validakey\ValidakeyFeistel;

/**
 * Parity and hardening tests for the Feistel cipher.
 *
 * The vectors are shared verbatim with the vkey server plugin. If these fail,
 * the two implementations have drifted and the Instance Token handshake will
 * fail to decrypt rather than reporting a useful error.
 */
final class ValidakeyFeistelTest extends TestCase
{
    /**
     * @return array<string, array{key: string, plaintext: string, ciphertext: string}>
     */
    public static function vectorProvider(): array
    {
        $raw = file_get_contents(__DIR__ . '/fixtures/feistel-vectors.json');
        self::assertNotFalse($raw, 'Missing feistel-vectors.json fixture.');

        $decoded = json_decode((string) $raw, true);
        $cases = array();
        foreach ($decoded['vectors'] as $vector) {
            $cases[$vector['name']] = array(
                'key' => $vector['key'],
                'plaintext' => '' === $vector['plaintext_hex'] ? '' : hex2bin($vector['plaintext_hex']),
                'ciphertext' => $vector['ciphertext'],
            );
        }

        return $cases;
    }

    #[DataProvider('vectorProvider')]
    public function testEncryptMatchesSharedVector(string $key, string $plaintext, string $ciphertext): void
    {
        self::assertSame($ciphertext, (new ValidakeyFeistel($key))->encrypt($plaintext));
    }

    #[DataProvider('vectorProvider')]
    public function testDecryptMatchesSharedVector(string $key, string $plaintext, string $ciphertext): void
    {
        self::assertSame($plaintext, (new ValidakeyFeistel($key))->decrypt($ciphertext));
    }

    public function testRoundTripPreservesBinaryPayloads(): void
    {
        $cipher = new ValidakeyFeistel('round-trip-key');
        $plaintext = random_bytes(8) . 'b3f1c2d4-1111-4aaa-8bbb-ccccdddd0001' . 'host.example.com';

        self::assertSame($plaintext, $cipher->decrypt($cipher->encrypt($plaintext)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedProvider(): array
    {
        return array(
            'empty' => array(''),
            'non hex' => array('zzzzzzzzzzzzzzzz'),
            'odd length' => array('abc'),
            'not block aligned' => array('aabbccdd'),
        );
    }

    #[DataProvider('malformedProvider')]
    public function testMalformedCiphertextIsRejected(string $bad): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ValidakeyFeistel('somekey'))->decrypt($bad);
    }

    /**
     * Padding validation is what makes the server's candidate-key walk cheap:
     * almost every wrong key is rejected before any further checks run.
     */
    public function testWrongKeyIsRejectedByPaddingValidation(): void
    {
        $ciphertext = (new ValidakeyFeistel('right'))->encrypt('b3f1c2d4-1111-4aaa-8bbb-ccccdddd0001');

        $rejected = 0;
        for ($i = 0; $i < 256; $i++) {
            try {
                (new ValidakeyFeistel('wrong' . $i))->decrypt($ciphertext);
            } catch (\Throwable $e) {
                $rejected++;
            }
        }

        self::assertGreaterThan(240, $rejected, 'Expected padding validation to reject nearly all wrong keys.');
    }
}
