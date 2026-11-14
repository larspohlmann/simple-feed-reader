<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Enum\SourceFormat;
use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Scraper\Support\TextNormalizer;
use App\Service\Url\Support\MixcloudUrl;
use Dom\HTMLDocument;

/** Offers the shows of the Mixcloud user a profile, show or API address names; Mixcloud publishes no RSS, only this API. */
final readonly class MixcloudProfileFeed implements FeedOfferInterface
{
    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        $profileUser = MixcloudUrl::profileUser($pageUrl);
        if ($profileUser !== null) {
            return $this->cloudcastsOf($profileUser, $this->pageTitle(HtmlDocumentParser::parseOrEmpty($body)));
        }
        $user = MixcloudUrl::showUser($pageUrl) ?? MixcloudUrl::apiUser($pageUrl);

        return $user === null ? null : $this->cloudcastsOf($user, null);
    }

    private function cloudcastsOf(string $user, ?string $title): FeedCandidateModel
    {
        return new FeedCandidateModel(MixcloudUrl::cloudcastsUrl($user), $title, SourceFormat::MIXCLOUD_API);
    }

    private function pageTitle(HTMLDocument $document): ?string
    {
        $title = TextNormalizer::normalize($document->querySelector('title')->textContent ?? '');

        return '' === $title ? null : $title;
    }
}
