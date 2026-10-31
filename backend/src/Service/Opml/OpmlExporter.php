<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Entity\Tag;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SubscriptionRepository;
use App\Service\Opml\Pass\OpmlTagTree;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Service\Social\Support\ExportedFeed;

/**
 * Serialises a user's subscriptions to OPML 2.0, grouped by tag, each tag nested in its
 * parent. A feed with several tags appears under each group (OPML is a tree); untagged feeds sit at
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

        $tree = new OpmlTagTree($subscriptions);
        foreach ($tree->roots() as $root) {
            $body->appendChild($this->tagOutline($document, $tree, $root));
        }
        foreach ($tree->untagged() as $exported) {
            $body->appendChild($this->feedOutline($document, $exported));
        }

        return (string) $document->saveXML();
    }

    /**
     * @throws \DOMException
     */
    private function tagOutline(\DOMDocument $document, OpmlTagTree $tree, Tag $tag): \DOMElement
    {
        $outline = $document->createElement('outline');
        $outline->setAttribute('text', $tag->getName());
        $outline->setAttribute('title', $tag->getName());
        foreach ($tree->feedsOf($tag) as $exported) {
            $outline->appendChild($this->feedOutline($document, $exported));
        }
        foreach ($tree->childrenOf($tag) as $child) {
            $outline->appendChild($this->tagOutline($document, $tree, $child));
        }

        return $outline;
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
        if (SourceFormat::isVerbatim($exported->feedSourceFormat)) {
            $outline->setAttribute('sourceFormat', $exported->feedSourceFormat);
        }
        if ($feed->getSiteUrl() !== null) {
            $outline->setAttribute('htmlUrl', $feed->getSiteUrl());
        }

        return $outline;
    }
}
