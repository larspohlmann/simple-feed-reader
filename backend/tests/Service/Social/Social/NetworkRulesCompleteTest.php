<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\PrivateFeedRules;
use App\Service\Social\Social\ProfileFeeds;
use App\Service\Social\Social\SocialPostAddresses;
use App\Service\Social\Social\SocialSearch\NetworkSocialSearch;
use App\Service\Social\Social\SocialSearch\SocialSearchInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A locator fails only at runtime where a `match` failed PHPStan: every network needs its adapter in the container. */
final class NetworkRulesCompleteTest extends KernelTestCase
{
    #[DataProvider('ruleTags')]
    public function testEveryNetworkHasARule(string $pickerServiceId): void
    {
        $selectRule = [self::getContainer()->get($pickerServiceId), 'selectRule'];
        self::assertIsCallable($selectRule);

        foreach (SocialNetwork::cases() as $network) {
            self::assertIsObject($selectRule($network), $network->value);
        }
    }

    public function testEveryNetworkHasASearchSource(): void
    {
        $search = self::getContainer()->get(SocialSearchInterface::class);
        self::assertInstanceOf(NetworkSocialSearch::class, $search);
        $sources = new \ReflectionProperty(NetworkSocialSearch::class, 'sources')->getValue($search);
        self::assertInstanceOf(ContainerInterface::class, $sources);

        foreach (SocialNetwork::cases() as $network) {
            self::assertTrue($sources->has($network->value), $network->value);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function ruleTags(): iterable
    {
        yield 'post addresses' => [SocialPostAddresses::class];
        yield 'profile feeds' => [ProfileFeeds::class];
        yield 'private feeds' => [PrivateFeedRules::class];
    }
}
