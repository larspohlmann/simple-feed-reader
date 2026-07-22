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

    public function export(User $user): string
    {
        $subs = $this->subscriptions->findForUserWithTags((int) $user->getId());

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $opml = $doc->createElement('opml');
        $opml->setAttribute('version', '2.0');
        $doc->appendChild($opml);

        $head = $doc->createElement('head');
        $head->appendChild($doc->createElement('title', 'Simple Feed Reader subscriptions'));
        $opml->appendChild($head);

        $body = $doc->createElement('body');
        $opml->appendChild($body);

        [$byTag, $untagged] = $this->group($subs);

        foreach ($byTag as $tagName => $group) {
            $outline = $doc->createElement('outline');
            $outline->setAttribute('text', (string) $tagName);
            $outline->setAttribute('title', (string) $tagName);
            foreach ($group as $sub) {
                $outline->appendChild($this->feedOutline($doc, $sub));
            }
            $body->appendChild($outline);
        }

        foreach ($untagged as $sub) {
            $body->appendChild($this->feedOutline($doc, $sub));
        }

        return (string) $doc->saveXML();
    }

    /**
     * @param list<Subscription> $subs
     *
     * @return array{0: array<string, list<Subscription>>, 1: list<Subscription>}
     */
    private function group(array $subs): array
    {
        $byTag = [];
        $untagged = [];
        foreach ($subs as $sub) {
            $tags = $sub->getTags();
            if ($tags->isEmpty()) {
                $untagged[] = $sub;
                continue;
            }
            foreach ($tags as $tag) {
                $byTag[$tag->getName()][] = $sub;
            }
        }

        return [$byTag, $untagged];
    }

    private function feedOutline(\DOMDocument $doc, Subscription $sub): \DOMElement
    {
        $feed = $sub->getFeed();
        $title = $sub->getCustomTitle() ?? $feed->getTitle() ?? $feed->getUrl();

        $outline = $doc->createElement('outline');
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
