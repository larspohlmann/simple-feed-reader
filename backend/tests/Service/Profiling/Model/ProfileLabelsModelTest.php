<?php

declare(strict_types=1);

namespace App\Tests\Service\Profiling\Model;

use App\Service\Profiling\Model\ProfileLabelsModel;
use PHPUnit\Framework\TestCase;

final class ProfileLabelsModelTest extends TestCase
{
    public function testWebRequestLabelsCarryTheTraceAndSpan(): void
    {
        $name = ProfileLabelsModel::forWebRequest('abc', 'def')->toNameParameter('simple-feed-reader');

        self::assertSame(
            'simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def}',
            $name,
        );
    }

    public function testWorkerLabelsCarryOnlyServiceAndProcess(): void
    {
        $name = ProfileLabelsModel::forWorker()->toNameParameter('simple-feed-reader');

        self::assertSame('simple-feed-reader{service_name=simple-feed-reader,process=worker}', $name);
    }

    public function testWithRouteAppendsARouteLabelToTheWebRequestLabels(): void
    {
        $name = ProfileLabelsModel::forWebRequest('abc', 'def')
            ->withRoute('api_entries_list')
            ->toNameParameter('simple-feed-reader');

        self::assertSame(
            'simple-feed-reader{service_name=simple-feed-reader,process=web,trace_id=abc,span_id=def,'
            . 'route=api_entries_list}',
            $name,
        );
    }

    public function testWithRouteLeavesTheOriginalLabelsUntouched(): void
    {
        $original = ProfileLabelsModel::forWebRequest('abc', 'def');
        $original->withRoute('api_entries_list');

        self::assertStringNotContainsString('route=', $original->toNameParameter('simple-feed-reader'));
    }
}
