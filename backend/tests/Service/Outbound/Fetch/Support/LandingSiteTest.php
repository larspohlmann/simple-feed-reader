<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Fetch\Support;

use App\Service\Outbound\Fetch\Support\LandingSite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LandingSiteTest extends TestCase
{
    #[DataProvider('landings')]
    public function testASiteIsNamedAfterWhereItsLinkLands(string $postedUrl, string $landingUrl, string $site): void
    {
        self::assertSame($site, LandingSite::pickHost($postedUrl, $landingUrl));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function landings(): iterable
    {
        yield 'another site' => ['https://n.pr/a', 'https://www.npr.org/a.html', 'npr.org'];
        yield 'the same host' => ['https://taz.de/a', 'https://taz.de/a/', 'taz.de'];
        yield 'a subdomain of the posted site' => ['https://fsfe.org/donate', 'https://my.fsfe.org/donate', 'fsfe.org'];
        yield 'the site above the posted host' => ['https://m.taz.de/a', 'https://taz.de/a', 'taz.de'];
        yield 'a host that only ends alike' => ['https://pr.org/a', 'https://npr.org/a', 'npr.org'];
    }
}
