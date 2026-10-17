<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Support\BlueskyProfileLabels;
use App\Tests\Support\BlueskyProfiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlueskyProfileLabelsTest extends TestCase
{
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
            'src' => BlueskyProfiles::RUSTY,
            'uri' => 'at://' . BlueskyProfiles::RUSTY . '/app.bsky.actor.profile/self',
            'val' => '!no-unauthenticated',
        ];
        $other = ['src' => BlueskyProfiles::RUSTY, 'val' => 'porn'];
        yield 'its own label' => [['did' => BlueskyProfiles::RUSTY, 'labels' => [$own]], true];
        yield 'among other labels' => [['did' => BlueskyProfiles::RUSTY, 'labels' => [$other, $own]], true];
        yield 'set by a labeller' => [
            ['did' => BlueskyProfiles::RUSTY, 'labels' => [['src' => 'did:plc:labeller'] + $own]],
            false,
        ];
        yield 'another value' => [['did' => BlueskyProfiles::RUSTY, 'labels' => [['val' => '!hide'] + $own]], false];
        yield 'negated' => [['did' => BlueskyProfiles::RUSTY, 'labels' => [['neg' => true] + $own]], false];
        yield 'no labels' => [['did' => BlueskyProfiles::RUSTY], false];
        yield 'neither did nor src' => [['labels' => [['val' => '!no-unauthenticated']]], false];
    }
}
