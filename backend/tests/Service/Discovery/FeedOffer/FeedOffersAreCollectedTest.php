<?php

declare(strict_types=1);

namespace App\Tests\Service\Discovery\FeedOffer;

use App\Service\Discovery\FeedDiscovery\FeedDiscovery;
use App\Service\Discovery\FeedOffer\FeedOfferInterface;
use App\Service\Discovery\FeedOffer\WordPressRestProbe;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** An untagged interface leaves #[AutowireIterator] empty without an error; this reads what discovery really got. */
final class FeedOffersAreCollectedTest extends KernelTestCase
{
    public function testTheContainerHandsDiscoveryEveryFeedOffer(): void
    {
        $discovery = self::getContainer()->get(FeedDiscovery::class);
        self::assertInstanceOf(FeedDiscovery::class, $discovery);

        $offers = (new \ReflectionProperty($discovery, 'offers'))->getValue($discovery);
        self::assertIsIterable($offers);

        $classes = [];
        foreach ($offers as $offer) {
            self::assertInstanceOf(FeedOfferInterface::class, $offer);
            $classes[] = $offer::class;
        }

        self::assertContains(WordPressRestProbe::class, $classes);
    }
}
