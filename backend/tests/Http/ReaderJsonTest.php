<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Http\ReaderJson;
use App\Service\Reader\Model\ExtractionFailure;
use App\Service\Reader\Model\ExtractionResultModel;
use PHPUnit\Framework\TestCase;

final class ReaderJsonTest extends TestCase
{
    public function testAFailedExtractionKeepsTheUrlItFailedOn(): void
    {
        $result = ExtractionResultModel::failed('https://example.com/article', ExtractionFailure::Fetch, 'HTTP 503');

        $json = ReaderJson::one($result, null, new \DateTimeImmutable('2026-09-07T00:00:00Z'));

        self::assertSame(
            [
                'status' => 'failed',
                'url' => 'https://example.com/article',
                'reason' => 'fetch',
                'detail' => 'HTTP 503',
                'originalHero' => null,
            ],
            $json,
        );
    }

    public function testASuccessfulExtractionCarriesTheArticleFields(): void
    {
        $result = ExtractionResultModel::ok(
            'https://example.com/article',
            'Headline',
            'A. Writer',
            'Example',
            '<p>Body.</p>',
            'Body.',
        );

        $json = ReaderJson::one($result, null, new \DateTimeImmutable('2026-09-07T00:00:00Z'));

        self::assertSame(
            [
                'status' => 'ok',
                'url' => 'https://example.com/article',
                'title' => 'Headline',
                'byline' => 'A. Writer',
                'siteName' => 'Example',
                'contentHtml' => '<p>Body.</p>',
                'excerpt' => 'Body.',
                'paywalled' => false,
                'originalHero' => null,
                'extractedAt' => '2026-09-07T00:00:00+00:00',
            ],
            $json,
        );
    }
}
