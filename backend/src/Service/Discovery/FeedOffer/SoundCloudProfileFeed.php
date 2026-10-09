<?php

declare(strict_types=1);

namespace App\Service\Discovery\FeedOffer;

use App\Service\Discovery\Model\FeedCandidateModel;
use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\MetaProperty;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\HTMLDocument;

/**
 * Offers the RSS feed of the SoundCloud user a page deep-links to. Only a profile names a user there (a track page
 * names `soundcloud://sounds:…`), and the feed is unadvertised and may be empty: the dialog's preview shows which.
 */
final readonly class SoundCloudProfileFeed implements FeedOfferInterface
{
    private const string USER_DEEP_LINK = 'soundcloud://users:';

    private const string FEED_URL = 'https://feeds.soundcloud.com/users/soundcloud:users:%s/sounds.rss';

    public function offer(string $body, string $pageUrl): ?FeedCandidateModel
    {
        if (!str_contains($body, self::USER_DEEP_LINK)) {
            return null;
        }

        $document = HtmlDocumentParser::parseOrEmpty($body);
        $userId = $this->deepLinkedUserId($document);
        if (null === $userId) {
            return null;
        }

        return new FeedCandidateModel(sprintf(self::FEED_URL, $userId), $this->profileName($document), 'rss');
    }

    private function deepLinkedUserId(HTMLDocument $document): ?string
    {
        $deepLink = trim(MetaProperty::content($document, 'al:ios:url'));

        if (!str_starts_with($deepLink, self::USER_DEEP_LINK)) {
            return null;
        }

        $userId = substr($deepLink, \strlen(self::USER_DEEP_LINK));

        return ctype_digit($userId) ? $userId : null;
    }

    private function profileName(HTMLDocument $document): ?string
    {
        $name = TextNormalizer::normalize(MetaProperty::content($document, 'og:title'));

        return '' === $name ? null : $name;
    }
}
