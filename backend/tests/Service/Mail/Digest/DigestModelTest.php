<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestGroup;
use App\Service\Mail\Digest\DigestModel;
use PHPUnit\Framework\TestCase;

final class DigestModelTest extends TestCase
{
    public function testIsEmptyWhenNoGroups(): void
    {
        $model = new DigestModel([], 0);

        self::assertTrue($model->isEmpty());
    }

    public function testIsNotEmptyWhenGroupsPresent(): void
    {
        $entry = new DigestEntry('Title', 'Feed', 'Short description', 'https://example.test/1');
        $group = new DigestGroup('rust', 1, [$entry], false, 'https://example.test/more');
        $model = new DigestModel([$group], 1);

        self::assertFalse($model->isEmpty());
    }
}
