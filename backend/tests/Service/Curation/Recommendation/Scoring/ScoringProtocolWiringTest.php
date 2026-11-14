<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Scoring;

use App\Enum\ScoringProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocol\RerankProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocol\SystemOneProtocol;
use App\Service\Recommendation\Scoring\ScoringProtocolResolver;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** The resolver's unit tests hand-build the locator; only the compiled container proves the tag and the index. */
final class ScoringProtocolWiringTest extends KernelTestCase
{
    public function testEveryScoringProtocolHasItsImplementation(): void
    {
        self::bootKernel();
        $protocols = self::getContainer()->get(ScoringProtocolResolver::class);
        self::assertInstanceOf(ScoringProtocolResolver::class, $protocols);

        $wired = [];
        foreach (ScoringProtocol::cases() as $protocol) {
            $implementation = $protocols->protocolOf($protocol);
            $wired[$protocol->value] = $implementation::class;
        }

        self::assertSame(
            ['system_one' => SystemOneProtocol::class, 'rerank' => RerankProtocol::class],
            $wired,
        );
    }
}
