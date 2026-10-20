<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Reader\ReaderUrlSignature;

trait SignsReaderUrls
{
    private function readerTokenFor(string $url): string
    {
        $signature = self::getContainer()->get(ReaderUrlSignature::class);
        self::assertInstanceOf(ReaderUrlSignature::class, $signature);

        return $signature->tokenFor($url);
    }
}
