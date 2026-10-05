<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Support;

use App\Service\Recommendation\Support\RefusalMessage;
use PHPUnit\Framework\TestCase;

final class RefusalMessageTest extends TestCase
{
    public function testItQuotesTheReasonAfterTheStatus(): void
    {
        self::assertSame(
            'That provider refused the request (status 422): state too long',
            RefusalMessage::of(422, 'state too long'),
        );
    }

    public function testWithoutAReasonItStillNamesTheStatus(): void
    {
        self::assertSame('That provider refused the request (status 404).', RefusalMessage::of(404, null));
    }
}
