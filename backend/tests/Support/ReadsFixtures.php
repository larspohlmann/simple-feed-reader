<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

trait ReadsFixtures
{
    /** @param string $path relative to tests/Fixtures, e.g. 'soundcloud/profile.html' */
    private function fixture(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../Fixtures/' . $path);
        Assert::assertIsString($contents);

        return $contents;
    }
}
