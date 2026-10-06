<?php

declare(strict_types=1);

namespace Validakey\Tests;

use PHPUnit\Framework\TestCase;
use Validakey\Door;
use Validakey\Response\TokenResponse;

final class DoorTest extends TestCase
{
    public function testPackUnpackRoundTrip(): void
    {
        $seed = Door::hexToSeedBytes('0123456789abcdef');
        self::assertSame(8, strlen($seed));

        $mac = Door::mac48($seed, Door::bucket(1_700_000_000));
        self::assertSame(Door::MAC_BYTES, strlen($mac));

        $packed = Door::packToken('aabbccdd', '11223344', $mac);
        self::assertSame(Door::PACKED_CHARS, strlen($packed));

        $un = Door::unpackToken($packed);
        self::assertNotNull($un);
        self::assertSame('aabbccdd', $un['instance_pre']);
        self::assertSame('11223344', $un['token_pre']);
        self::assertSame($mac, $un['mac']);
    }

    public function testPackLiveAndRedeemUrl(): void
    {
        $unix = 1_700_000_060;
        $packed = Door::packLive('aabbccdd', '11223344', '0123456789abcdef', $unix);
        self::assertSame(Door::PACKED_CHARS, strlen($packed));

        $url = Door::redeemUrl('https://api.validakey.com/v1', $packed);
        self::assertSame('https://api.validakey.com/g/' . $packed . '/', $url);

        $live = Door::liveRedeemUrl(
            'https://api.example.com/v1/',
            'aabbccdd',
            '11223344',
            '0123456789abcdef',
            $unix
        );
        self::assertSame('https://api.example.com/g/' . $packed . '/', $live);
    }

    public function testMacMatchesAdjacentBuckets(): void
    {
        $seed = Door::hexToSeedBytes('fedcba9876543210');
        $now = 1_700_000_000;
        $n = Door::bucket($now);
        $mac = Door::mac48($seed, $n);

        self::assertTrue(Door::macMatches($seed, $mac, $now));
        self::assertFalse(Door::macMatches($seed, str_repeat("\0", 6), $now));
    }

    public function testTokenResponseDoorSeedAccessors(): void
    {
        $with = TokenResponse::fromArray(array(
            'out' => array(
                'token' => 'aabbccdd11223344deadbeef',
                'created' => 100,
                'expires_at' => 200,
                'door_seed' => '0123456789ABCDEF',
                'door_bucket_secs' => 60,
            ),
        ));
        self::assertSame('0123456789abcdef', $with->doorSeed());
        self::assertSame(60, $with->doorBucketSecs());

        $without = TokenResponse::fromArray(array(
            'out' => array(
                'token' => 'aabbccdd11223344deadbeef',
                'created' => 100,
                'expires' => 200,
            ),
        ));
        self::assertNull($without->doorSeed());
        self::assertNull($without->doorBucketSecs());

        $bad = TokenResponse::fromArray(array(
            'out' => array(
                'token' => 'x',
                'door_seed' => 12345,
            ),
        ));
        self::assertNull($bad->doorSeed());
    }

    public function testRejectsBadPackInputs(): void
    {
        self::assertSame('', Door::packToken('bad', '11223344', str_repeat("\0", 6)));
        self::assertNull(Door::unpackToken('short'));
        self::assertSame('', Door::redeemUrl('https://api.validakey.com/v1', 'nope'));
        self::assertSame('', Door::hexToSeedBytes('zzzz'));
    }
}
