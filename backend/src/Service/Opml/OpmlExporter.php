<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Service\Social\Support\ExportedFeed;

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
        $subscriptions = ExportedFeed::withoutRepeats(
            $this->subscriptions->findExportableForUserWithTags($user->requireId()),
        );

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
            foreach ($group as $exported) {
                $outline->appendChild($this->feedOutline($document, $exported));
            }
            $body->appendChild($outline);
        }

        foreach ($untagged as $exported) {
            $body->appendChild($this->feedOutline($document, $exported));
        }

        return (string) $document->saveXML();
    }

    /**
     * @param list<ExportedSubscriptionModel> $subscriptions
     *
     * @return array{0: array<string, list<ExportedSubscriptionModel>>, 1: list<ExportedSubscriptionModel>}
     */
    private function group(array $subscriptions): array
    {
        $byTag = [];
        $untagged = [];
        foreach ($subscriptions as $exported) {
            $tags = $exported->subscription->getTags();
            if ($tags->isEmpty()) {
                $untagged[] = $exported;
                continue;
            }
            foreach ($tags as $tag) {
                $byTag[$tag->getName()][] = $exported;
            }
        }

        return [$byTag, $untagged];
    }

    /**
     * @throws \DOMException
     */
    private function feedOutline(\DOMDocument $document, ExportedSubscriptionModel $exported): \DOMElement
    {
        $feed = $exported->subscription->getFeed();
        $title = $exported->subscription->getCustomTitle() ?? $feed->getTitle() ?? $exported->feedUrl;

        $outline = $document->createElement('outline');
        $outline->setAttribute('type', 'rss');
        $outline->setAttribute('text', $title);
        $outline->setAttribute('title', $title);
        $outline->setAttribute('xmlUrl', $exported->feedUrl);
        if ($feed->getSiteUrl() !== null) {
            $outline->setAttribute('htmlUrl', $feed->getSiteUrl());
        }

        return $outline;
    }
}
