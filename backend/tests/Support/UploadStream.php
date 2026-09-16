<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

final class UploadStream
{
    /** @return resource */
    public static function fromString(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        Assert::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
