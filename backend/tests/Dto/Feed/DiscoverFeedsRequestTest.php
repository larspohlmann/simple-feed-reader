<?php

declare(strict_types=1);

namespace App\Tests\Dto\Feed;

use App\Dto\Feed\DiscoverFeedsRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class DiscoverFeedsRequestTest extends KernelTestCase
{
    private function validator(): ValidatorInterface
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator;
    }

    public function testABareHostIsLookedUpOverHttps(): void
    {
        self::assertSame('https://heise.de', (new DiscoverFeedsRequest('  heise.de '))->url);
        self::assertCount(0, $this->validator()->validate(new DiscoverFeedsRequest('heise.de')));
    }

    public function testAnythingButAnHttpAddressFailsValidation(): void
    {
        self::assertGreaterThan(0, $this->validator()->validate(new DiscoverFeedsRequest('not a url'))->count());
        self::assertGreaterThan(
            0,
            $this->validator()->validate(new DiscoverFeedsRequest('ftp://example.com'))->count(),
        );
        self::assertGreaterThan(0, $this->validator()->validate(new DiscoverFeedsRequest(''))->count());
    }
}
