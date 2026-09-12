<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling;

use App\Service\Profiling\ProfileLabels;
use PHPUnit\Framework\TestCase;

final class ProfileLabelsTest extends TestCase
{
    public function testWebRequestLabelsCarryTheTraceAndSpan(): void
    {
        $name = ProfileLabels::forWebRequest('abc', 'def')->toNameParameter('simple-feed-reader');

        self::assertSame(
            'simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def}',
            $name,
        );
    }

    public function testWorkerLabelsCarryOnlyServiceAndProcess(): void
    {
        $name = ProfileLabels::forWorker()->toNameParameter('simple-feed-reader');

        self::assertSame('simple-feed-reader{service_name=simple-feed-reader,process=worker}', $name);
    }
}
