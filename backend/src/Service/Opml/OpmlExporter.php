<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SubscriptionRepository;

/**
 * Serialises a user's subscriptions to OPML 2.0, grouped by tag. A feed with
 * several tags appears under each group (OPML is a tree); untagged feeds sit at
 * the body root. DOMDocument handles all escaping.
 */
final readonly class OpmlExporter
{
    public function __construct(
        private SubscriptionRepository $subscriptions,
    ) {
    }

    /**
     * @throws \DOMException
     */
    public function export(User $user): string
    {
        $subscriptions = $this->subscriptions->findForUserWithTags($user->requireId());

        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;

        $opml = $document->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $document->appendChild($opml);

        $head = $document->createElement('head');
        $head->appendChild($document->createElement('title', 'Simple Feed Reader subscriptions'));
        $opml->appendChild($head);

        $body = $document->createElement('body');
        $opml->appendChild($body);

        [$byTag, $untagged] = $this->group($subscriptions);

        foreach ($byTag as $tagName => $group) {
            $outline = $document->createElement('outline');
            $outline->setAttribute('text', $tagName);
            $outline->setAttribute('title', $tagName);
            foreach ($group as $subscription) {
                $outline->appendChild($this->feedOutline($document, $subscription));
            }
            $body->appendChild($outline);
        }

        foreach ($untagged as $subscription) {
            $body->appendChild($this->feedOutline($document, $subscription));
        }

        return (string) $document->saveXML();
    }

    /**
     * @param list<Subscription> $subscriptions
     *
     * @return array{0: array<string, list<Subscription>>, 1: list<Subscription>}
     */
    private function group(array $subscriptions): array
    {
        $byTag = [];
        $untagged = [];
        foreach ($subscriptions as $subscription) {
            $tags = $subscription->getTags();
            if ($tags->isEmpty()) {
                $untagged[] = $subscription;
                continue;
            }
            foreach ($tags as $tag) {
                $byTag[$tag->getName()][] = $subscription;
            }
        }

        return [$byTag, $untagged];
    }

    /**
     * @throws \DOMException
     */
    private function feedOutline(\DOMDocument $document, Subscription $subscription): \DOMElement
    {
        $feed = $subscription->getFeed();
        $title = $subscription->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $outline = $document->createElement('outline');
        $outline->setAttribute('type', 'rss');
        $outline->setAttribute('text', $title);
        $outline->setAttribute('title', $title);
        $outline->setAttribute('xmlUrl', $feed->getUrl());
        if ($feed->getSiteUrl() !== null) {
            $outline->setAttribute('htmlUrl', $feed->getSiteUrl());
        }

        return $outline;
    }
}
