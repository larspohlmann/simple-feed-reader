<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Trending\GermanVerbStems;
use App\Tests\Support\TrendingWiring;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GermanVerbStemsTest extends TestCase
{
    #[DataProvider('stems')]
    public function testMapsAVerbFormToItsInfinitiveStem(string $stem, string $word, string $expected): void
    {
        $verbStems = new GermanVerbStems(TrendingWiring::germanLexicon());

        self::assertSame($expected, $verbStems->stemOf($stem, $word));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function stems(): iterable
    {
        yield 'a -t form whose present stem ends in -el' => ['verhandelt', 'verhandelt', 'verhandeln'];
        yield 'a -t form with a diacritic' => ['kundigt', 'kündigt', 'kundig'];
        yield 'an -et form' => ['arbeitet', 'arbeitet', 'arbeit'];
        yield 'the stem of an infinitive is the stem itself' => ['verhandeln', 'verhandeln', 'verhandeln'];
        yield 'a noun is guarded although its stem is a verb stem' => ['bett', 'bett', 'bett'];
        yield 'a noun ending in -t is guarded' => ['macht', 'macht', 'macht'];
        yield 'a stem that is no verb stem stays' => ['rot', 'rot', 'rot'];
        yield 'a verb stem itself is mapped' => ['nominier', 'nominieren', 'nominier'];
    }
}
