<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidOpmlException;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

/**
 * Imports an OPML file into a user's subscriptions WITHOUT fetching anything.
 * Feeds are find-or-created and marked due (nextFetchAt = now) so the next
 * refresh cycle populates them; the fetcher's SSRF guard applies then. The XML
 * is hardened the same way FeedParser hardens feeds: no network, no DTD.
 */
final readonly class OpmlImporter
{
    private const MAX_TAG_NAME = 100;

    /**
     * Feed.url is VARCHAR(750); a longer URL would pass a naive scheme/host
     * check yet blow up the deferred flush with "data too long" on MySQL
     * (strict mode), losing the whole import. Bound it here so an over-long URL
     * is merely counted invalid and the rest still imports.
     */
    private const MAX_FEED_URL = 750;

    public function __construct(
        private EntityManagerInterface $em,
        private FeedRepository $feeds,
        private SubscriptionRepository $subscriptions,
        private SubscriptionTagRepository $subscriptionTags,
        private TagRepository $tags,
        private ClockInterface $clock,
    ) {
    }

    public function import(User $user, string $opml): OpmlImportResult
    {
        $body = $this->parseBody($opml);

        $userId = (int) $user->getId();
        $existing = $this->subscriptions->countForUser($userId);
        $result = new OpmlImportResult();

        // Nothing is flushed until the very end, so a repository lookup cannot
        // see rows created earlier in THIS import. Two batch-local maps stand in
        // for that, guarding the three unique constraints the deferred flush
        // would otherwise trip:
        //  - $tagCache (uniq_tag_user_name): a group naming several feeds reuses
        //    one Tag row instead of persisting a duplicate.
        //  - $seen (uniq_subscription_user_feed): a URL listed under several
        //    folders — common in real exports — subscribes once; repeats count
        //    as alreadySubscribed rather than persisting a second Subscription.
        /** @var array<string, Tag> $tagCache keyed by lowercased name */
        $tagCache = [];
        /** @var array<string, true> $seen feed URLs subscribed during this import */
        $seen = [];

        // Nothing flushes until the end, so DB MAX(position) can't see rows made
        // in this batch — seed once from the committed max and count in memory so
        // imported tags/feeds get distinct, appended positions.
        $nextSubPosition = $this->subscriptions->nextPositionForUser($userId);
        $nextTagPosition = $this->tags->nextPositionForUser($userId);
        /** @var array<int, int> $nextFeedPositionInTag keyed by spl_object_id(tag) */
        $nextFeedPositionInTag = [];

        // Depth-first: each feed outline inherits the nearest ancestor group's
        // title as its tag. `null` tag = body root (untagged).
        foreach ($this->collectFeeds($body, null) as [$xmlUrl, $tagName]) {
            if (!$this->isImportableUrl($xmlUrl)) {
                $result = $result->with(invalid: 1);
                continue;
            }

            if (isset($seen[$xmlUrl])) {
                $result = $result->with(alreadySubscribed: 1);
                continue;
            }

            // Look up but do NOT create yet: an over-limit import must not leave
            // orphan Feed rows behind for feeds it never subscribes.
            $feed = $this->feeds->findOneBy(['url' => $xmlUrl]);
            if ($feed !== null && $this->subscriptions->existsForUserAndFeed($userId, (int) $feed->getId())) {
                $result = $result->with(alreadySubscribed: 1);
                continue;
            }

            if ($existing >= SubscriptionService::MAX_SUBSCRIPTIONS_PER_USER) {
                $result = $result->with(skippedOverLimit: 1);
                continue;
            }

            if ($feed === null) {
                $feed = new Feed($xmlUrl);
                $feed->setNextFetchAt($this->clock->now()); // due now → next refresh populates it
                $this->em->persist($feed);
            }

            $sub = new Subscription($user, $feed, $this->clock->now());
            $sub->setPosition($nextSubPosition++);
            $tag = $this->resolveTag($user, $tagName, $tagCache, $nextTagPosition);
            $this->attachTag($sub, $tag, $nextFeedPositionInTag);
            $this->em->persist($sub);
            $seen[$xmlUrl] = true;
            $existing++;
            $result = $result->with(imported: 1);
        }

        $this->em->flush();

        return $result;
    }

    private function parseBody(string $opml): \DOMElement
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $doc->loadXML($opml, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $doc->documentElement;
        if ($loaded === false || $root === null || $doc->doctype !== null || $root->localName !== 'opml') {
            throw new InvalidOpmlException('Not a well-formed OPML 2.0 document.');
        }

        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new InvalidOpmlException('OPML has no <body>.');
        }

        return $body;
    }

    /**
     * @return list<array{0: string, 1: string|null}> [xmlUrl, tagName]
     */
    private function collectFeeds(\DOMElement $node, ?string $inheritedTag): array
    {
        $out = [];
        foreach ($node->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
                continue;
            }

            $xmlUrl = trim($child->getAttribute('xmlUrl'));
            if ($xmlUrl !== '') {
                $out[] = [$xmlUrl, $inheritedTag];
                continue;
            }

            // A group outline: its text/title becomes the tag for descendants.
            $groupName = trim($child->getAttribute('text'));
            if ($groupName === '') {
                $groupName = trim($child->getAttribute('title'));
            }
            $childTag = $groupName !== '' ? $groupName : $inheritedTag;
            foreach ($this->collectFeeds($child, $childTag) as $descendant) {
                $out[] = $descendant;
            }
        }

        return $out;
    }

    private function isImportableUrl(string $url): bool
    {
        if (mb_strlen($url) > self::MAX_FEED_URL) {
            return false;
        }
        $scheme = parse_url($url, \PHP_URL_SCHEME);
        $host = parse_url($url, \PHP_URL_HOST);

        return \in_array($scheme, ['http', 'https'], true) && \is_string($host) && $host !== '';
    }

    /**
     * @param array<string, Tag> $cache batch-local identity map, keyed by lowercased name
     */
    /**
     * Attach the resolved tag (if any) at the next position within that tag,
     * counting in memory since nothing is flushed yet. A tag created in this
     * batch has no id and no committed feeds, so its order starts at 0; an
     * existing tag appends past its current feeds.
     *
     * @param array<int, int> $nextFeedPositionInTag keyed by spl_object_id(tag)
     */
    private function attachTag(Subscription $sub, ?Tag $tag, array &$nextFeedPositionInTag): void
    {
        if (null === $tag) {
            return;
        }
        $oid = spl_object_id($tag);
        $nextFeedPositionInTag[$oid] ??= null === $tag->getId()
            ? 0
            : $this->subscriptionTags->nextPositionForTag($tag);
        $sub->addTag($tag, $nextFeedPositionInTag[$oid]++);
    }

    /**
     * @param array<string, Tag> $cache
     */
    private function resolveTag(User $user, ?string $name, array &$cache, int &$nextTagPosition): ?Tag
    {
        if ($name === null) {
            return null;
        }
        $name = mb_substr($name, 0, self::MAX_TAG_NAME);
        $key = mb_strtolower($name);

        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $tag = $this->tags->findOneByNameForUser((int) $user->getId(), $name);
        if ($tag === null) {
            $tag = new Tag($user, $name);
            $tag->setPosition($nextTagPosition++);
            $this->em->persist($tag);
        }

        return $cache[$key] = $tag;
    }
}
