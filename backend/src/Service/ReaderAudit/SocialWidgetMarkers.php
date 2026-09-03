<?php

declare(strict_types=1);

namespace App\Service\ReaderAudit;

/**
 * Share bars and social rows the widget remover missed, found by where the
 * links point rather than what they say — an icon-only share button carries
 * no text to match, and its wording differs on every site anyway.
 *
 * A share-intent URL is proof on its own: nothing but a share button links to
 * facebook.com/sharer or twitter.com/intent/tweet. A profile link is not, nor
 * is a row of them — an Attack Magazine interview links the artist's Spotify,
 * Instagram and TikTok inside its own sentences. A share bar stands alone,
 * its links in blocks with no prose around them (#744).
 */
final readonly class SocialWidgetMarkers
{
    /** Paths that exist for one purpose: handing this page to a network. */
    private const array SHARE_INTENTS = [
        'facebook.com/sharer', 'facebook.com/dialog/share', 'twitter.com/intent', 'x.com/intent',
        'wa.me/?text', 'api.whatsapp.com/send', 't.me/share', 'telegram.me/share',
        'linkedin.com/share', 'linkedin.com/sharing', 'reddit.com/submit', 'pinterest.com/pin/create',
        'tumblr.com/share', 'getpocket.com/edit', 'flipboard.com/bookmarklet', 'xing.com/spi/shares',
        'service.weibo.com/share', 'mailto:?subject', 'mailto:?body', 'threads.net/intent',
        'bsky.app/intent', 'mastodon.social/share',
    ];

    private const array SOCIAL_HOSTS = [
        'facebook.com', 'twitter.com', 'x.com', 'instagram.com', 'linkedin.com', 'pinterest.com',
        'reddit.com', 'tiktok.com', 'threads.net', 'bsky.app', 'mastodon.social', 'tumblr.com',
        'whatsapp.com', 'telegram.me', 't.me', 'xing.com', 'vk.com', 'youtube.com',
    ];

    /** Below this many characters an anchor is an icon label, not a sentence linking out. */
    private const int ICON_TEXT_CHARS = 25;
    private const int MIN_SOCIAL_ROW = 3;

    /** A query value that is itself an address — http(s), plain or percent-encoded. */
    private const string PAGE_URL_PATTERN = '#https?(://|%3a%2f%2f)#i';

    /** @return list<CleanupMarker> */
    public function detect(ExtractedBody $body): array
    {
        $candidates = [$this->shareIntent($body), $this->socialRow($body)];

        return array_values(array_filter($candidates));
    }

    private function shareIntent(ExtractedBody $body): ?CleanupMarker
    {
        foreach ($body->links as $link) {
            $href = strtolower($link->href);
            $matchesAnIntent = array_any(
                self::SHARE_INTENTS,
                fn (string $intent): bool => str_contains($href, $intent),
            );
            if ($matchesAnIntent && $this->carriesThePageUrl($link->href)) {
                return new CleanupMarker(
                    'share_intent_link',
                    4,
                    'ShareWidgetRemover',
                    'share button: ' . $link->href,
                );
            }
        }

        return null;
    }

    /**
     * The mark of a real share button, not an editorial link to a share host:
     * its own query string carries an absolute page address.
     */
    private function carriesThePageUrl(string $href): bool
    {
        $query = strstr($href, '?');

        return $query !== false && preg_match(self::PAGE_URL_PATTERN, $query) === 1;
    }

    private function socialRow(ExtractedBody $body): ?CleanupMarker
    {
        $hosts = [];
        foreach ($body->blocks as $block) {
            if (!$block->isChrome()) {
                continue;
            }
            foreach ($block->links as $link) {
                $host = $this->socialHostOf($link);
                if ($host !== null && mb_strlen($link->text) <= self::ICON_TEXT_CHARS) {
                    $hosts[] = $host;
                }
            }
        }
        $distinct = array_unique($hosts);
        if (\count($distinct) < self::MIN_SOCIAL_ROW) {
            return null;
        }

        return new CleanupMarker(
            'social_row',
            3,
            'ShareWidgetRemover',
            'icon links to ' . implode(', ', $distinct),
        );
    }

    private function socialHostOf(BodyLink $link): ?string
    {
        $host = $link->host();
        foreach (self::SOCIAL_HOSTS as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                return $social;
            }
        }

        return null;
    }
}
