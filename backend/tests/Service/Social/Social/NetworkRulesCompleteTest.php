<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Enum\SocialNetwork;
use App\Service\Social\Social\SocialPostAddresses;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return iterable<string, array{string}> */
    public static function ruleTags(): iterable
    {
        yield 'post addresses' => [SocialPostAddresses::class];
    }
}
