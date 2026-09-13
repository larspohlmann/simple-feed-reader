<?php

declare(strict_types=1);

namespace App\Tests\Service\Tracing;

use App\Repository\EntryListRepository;
use App\Repository\EntryStateRepository;
use App\Repository\SavedSearchRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Reader\ArticleExtractor;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\HtmlPageFetcher;
use App\Service\Reader\Media\PageMediaScanner;
use App\Service\Reader\ReaderBodyCleaner;
use App\Service\Recommendation\ForYouFeedResponder;
use App\Service\Recommendation\RecommendationFeedPager;
use App\Service\Recommendation\RecommendationPollDriver;
use App\Service\Recommendation\RecommendationRunStatusPayload;
use App\Service\Sanitize\EntrySanitizer;
use App\Service\Search\SavedSearchMatchIds;
use OpenTelemetry\API\Instrumentation\WithSpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The entry-point methods of the hot API routes are traced as child spans, so the
 * performance dashboard can show where a route spends its time (#1011).
 */
final class TracedServiceMethodsTest extends TestCase
{
    /** @return iterable<string, array{class-string, string}> */
    public static function tracedMethods(): iterable
    {
        yield 'entries list' => [EntryListRepository::class, 'listForUser'];
        yield 'entries list, for-you responder' => [ForYouFeedResponder::class, 'page'];
        yield 'entries list, for-you pager' => [RecommendationFeedPager::class, 'page'];
        yield 'reader, ownership lookup' => [EntryListRepository::class, 'findOneSubscribedByUser'];
        yield 'reader, extraction' => [ArticleExtractor::class, 'extract'];
        yield 'reader, fetch' => [HtmlPageFetcher::class, 'fetch'];
        yield 'reader, normalise' => [FetchedPageNormalizer::class, 'normalize'];
        yield 'reader, media scan' => [PageMediaScanner::class, 'scan'];
        yield 'reader, readability' => [ArticleExtractor::class, 'richestArticle'];
        yield 'reader, body clean' => [ReaderBodyCleaner::class, 'clean'];
        yield 'reader, sanitise' => [EntrySanitizer::class, 'sanitize'];
        yield 'subscriptions list' => [SubscriptionRepository::class, 'findForUserWithTags'];
        yield 'subscriptions list, unread counts' => [EntryStateRepository::class, 'unreadCountsForUser'];
        yield 'subscriptions list, state counts' => [EntryStateRepository::class, 'stateCountsForUser'];
        yield 'tags list' => [TagRepository::class, 'findForUser'];
        yield 'saved searches list' => [SavedSearchRepository::class, 'findForUser'];
        yield 'saved searches list, matches' => [SavedSearchMatchIds::class, 'forAll'];
        yield 'recommendations current, poll' => [RecommendationPollDriver::class, 'current'];
        yield 'recommendations current, payload' => [RecommendationRunStatusPayload::class, 'forReport'];
    }

    /** @param class-string $class */
    #[DataProvider('tracedMethods')]
    public function testTheMethodOpensASpan(string $class, string $method): void
    {
        $attributes = new \ReflectionMethod($class, $method)->getAttributes(WithSpan::class);

        self::assertCount(1, $attributes, sprintf('%s::%s must carry #[WithSpan]', $class, $method));
    }
}
