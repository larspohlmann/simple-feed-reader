<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Ai\Support;

use App\Service\Curation\Ai\Exception\ProviderUnreachableException;
use App\Service\Curation\Ai\Support\ResponseByteCap;
use PHPUnit\Framework\TestCase;

final class ResponseByteCapTest extends TestCase
{
    public function testABodyOfExactlyTheCapPasses(): void
    {
        ResponseByteCap::onProgress(10)(10);

        $this->addToAssertionCount(1);
    }

    public function testOneByteMoreIsRefused(): void
    {
        $this->expectException(ProviderUnreachableException::class);
        $this->expectExceptionMessage('That provider answered with more than 10 bytes.');

        ResponseByteCap::onProgress(10)(11);
    }
}
