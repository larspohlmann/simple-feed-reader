<?php

declare(strict_types=1);

namespace App\Service\Sourcing\Preview;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Service\Sourcing\Discovery\ScrapeFallbackPolicy;
use App\Service\Outbound\Fetch\Exception\FetchException;
use App\Service\Outbound\Fetch\Exception\ResponseTooLargeException;
use App\Service\Outbound\Fetch\FeedFetcher\FeedFetcherInterface;
use App\Service\Ingestion\Parser\Exception\FeedParseException;
use App\Service\Sourcing\Preview\Exception\FeedPreviewException;
use App\Service\Sourcing\Preview\Factory\FeedPreviewFactory;
use App\Service\Sourcing\Preview\Model\FeedPreviewModel;
use App\Service\Ingestion\Refresh\FeedBodyParser;

/**
 * Fetches a feed URL and summarizes its content shape — how many items it has,
 * whether they carry full articles or bare titles, and a handful of sample
 * items — so a caller can preview a feed before subscribing to it.
 */
final readonly class FeedPreviewService
{
    public function __construct(
        private FeedFetcherInterface $fetcher,
        private FeedBodyParser $bodyParser,
        private ScrapeFallbackPolicy $scrapeFallbackPolicy,
        private FeedPreviewFactory $previewFactory,
    ) {
    }

    public function preview(User $user, string $url, ?string $format = null): FeedPreviewModel
    {
        // A preview asserting 'scraped' is a hand-made request discovery never saw, so the policy checks it here too.
        if (SourceFormat::SCRAPED === $format) {
            $this->scrapeFallbackPolicy->assertMayScrape($user);
        }

        try {
            $response = $this->fetcher->fetch($url);
        } catch (ResponseTooLargeException $exception) {
            throw new FeedPreviewException('The feed is larger than the reader accepts.', 0, $exception);
        } catch (FetchException $exception) {
            throw new FeedPreviewException('The feed could not be loaded.', 0, $exception);
        }

        $body = $response->modifiedBody();
        if (trim($body) === '') {
            throw new FeedPreviewException('The feed returned an empty document.');
        }

        try {
            $feed = $this->bodyParser->parseAs($format ?? SourceFormat::XML, $body, $response->finalUrl);
        } catch (FeedParseException $exception) {
            // A scraped preview keeps the extractor's message, which already names the problem in the user's words.
            throw new FeedPreviewException(
                $format === SourceFormat::SCRAPED ? $exception->getMessage() : 'That address is not a readable feed.',
                0,
                $exception,
            );
        }

        return $this->previewFactory->fromDocument($feed);
    }
}
