<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Enum\SourceFormat;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\MixcloudUrl;
use Dom\HTMLDocument;

/** Offers the shows of the Mixcloud user a profile page belongs to; Mixcloud publishes no RSS, only this API. */
final readonly class MixcloudProfileFeed implements FeedOfferInterface
{
    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $user = MixcloudUrl::profileUser($pageUrl);
        if ($user === null) {
            return null;
        }

        return new FeedCandidateModel(
            MixcloudUrl::cloudcastsUrl($user),
            $this->pageTitle(HtmlDocumentParser::parseOrEmpty($body)),
            SourceFormat::MIXCLOUD_API,
        );
    }

    private function pageTitle(HTMLDocument $document): ?string
    {
        $title = TextNormalizer::normalize($document->querySelector('title')->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
