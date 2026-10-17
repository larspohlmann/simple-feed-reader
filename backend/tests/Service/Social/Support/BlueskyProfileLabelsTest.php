<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\BlueskyProfileLabels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileLabelsTest extends TestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    /** @param array<string, mixed> $profile */
    #[DataProvider('profiles')]
    public function testOnlyTheProfilesOwnLabelHidesIt(array $profile, bool $hidden): void
    {
        self::assertSame($hidden, BlueskyProfileLabels::hidesFromLoggedOut(JsonNodeModel::of($profile)));
    }

    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function profiles(): iterable
    {
        $own = [
            'src' => self::RUSTY,
            'uri' => 'at://' . self::RUSTY . '/app.bsky.actor.profile/self',
            'val' => '!no-unauthenticated',
        ];
        $other = ['src' => self::RUSTY, 'val' => 'porn'];
        yield 'its own label' => [['did' => self::RUSTY, 'labels' => [$own]], true];
        yield 'among other labels' => [['did' => self::RUSTY, 'labels' => [$other, $own]], true];
        yield 'set by a labeller' => [
            ['did' => self::RUSTY, 'labels' => [['src' => 'did:plc:labeller'] + $own]],
            false,
        ];
        yield 'another value' => [['did' => self::RUSTY, 'labels' => [['val' => '!hide'] + $own]], false];
        yield 'negated' => [['did' => self::RUSTY, 'labels' => [['neg' => true] + $own]], false];
        yield 'no labels' => [['did' => self::RUSTY], false];
        yield 'neither did nor src' => [['labels' => [['val' => '!no-unauthenticated']]], false];
    }
}
