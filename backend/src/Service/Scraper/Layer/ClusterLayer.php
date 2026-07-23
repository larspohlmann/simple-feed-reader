<?php

declare(strict_types=1);

namespace App\Service\Scraper\Layer;

use App\Service\Scraper\CardFields;
use App\Service\Scraper\ScrapedItem;
use Dom\Element;
use Dom\HTMLDocument;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * Last-resort layer for pages without JSON-LD or article markup: clusters
 * anchors by their own class tokens (BEM modifiers stripped), assuming a
 * listing repeats one card component many times. An anchor joins one group
 * per token, so card variants that share a component class (featured vs.
 * plain mntl-card) still land in one cluster even when their wrapper depth
 * differs per page section — an ancestor-path signature fragmented exactly
 * there. Navigation, header, footer, and aside subtrees are excluded so
 * menus and footer link lists can never win. The biggest cluster (mean
 * title length breaks ties) becomes the item list, in document order.
 */
#[AsTaggedItem(priority: 10)]
final class ClusterLayer implements ScrapeLayerInterface
{
    private const int MIN_CLUSTER_SIZE = 3;
    private const int MAX_CONTAINER_HOPS = 3;

    public function extract(HTMLDocument $doc, string $baseUrl): array
    {
        // Eligible-anchor counts memoized per element, scoped to this one
        // pass (never static — documents differ between calls): container()
        // asks for the parent's count once per anchor, so N anchors sharing
        // one parent used to rescan that parent's whole subtree N times —
        // O(N²), about 10s for 2,000 flat sibling links, worse within the
        // 5MB fetch cap. The memo makes it one scan plus O(1) lookups.
        /** @var \SplObjectStorage<Element, int> $counts */
        $counts = new \SplObjectStorage();

        $groups = [];
        foreach ($doc->querySelectorAll('a[href]') as $anchor) {
            if (!$this->isEligible($anchor)) {
                continue;
            }
            foreach ($this->groupKeys($anchor) as $key) {
                $groups[$key][] = $anchor;
            }
        }

        $best = [];
        foreach ($groups as $anchors) {
            if (\count($anchors) < self::MIN_CLUSTER_SIZE) {
                continue;
            }
            $items = $this->items($anchors, $baseUrl, $counts);
            if (\count($items) >= self::MIN_CLUSTER_SIZE && $this->beats($items, $best)) {
                $best = $items;
            }
        }

        return $best;
    }

    /** Page chrome never carries the article list; fragment links never lead to one. */
    private function isEligible(Element $anchor): bool
    {
        if (str_starts_with($anchor->getAttribute('href') ?? '', '#')) {
            return false;
        }

        return $anchor->closest('nav, header, footer, aside') === null;
    }

    /**
     * One group key per class token, with BEM modifier suffixes stripped so
     * card--featured and card--no-image variants share the card group.
     * Classless anchors all share the bare "a." group.
     *
     * @return list<string>
     */
    private function groupKeys(Element $anchor): array
    {
        preg_match_all('/\S+/', $anchor->getAttribute('class') ?? '', $matches);
        $keys = [];
        foreach ($matches[0] as $token) {
            $keys[] = 'a.' . strtolower((string) preg_replace('/--.*$/', '', $token));
        }

        return $keys === [] ? ['a.'] : array_values(array_unique($keys));
    }

    /**
     * Dedupes by URL (first occurrence wins) before scoring, so repeated
     * same-URL links cannot inflate a group.
     *
     * @param list<Element> $anchors
     * @param \SplObjectStorage<Element, int> $counts
     * @return list<ScrapedItem>
     */
    private function items(array $anchors, string $baseUrl, \SplObjectStorage $counts): array
    {
        $items = [];
        foreach ($anchors as $anchor) {
            $item = CardFields::item($this->container($anchor, $counts), $anchor, $baseUrl);
            if ($item !== null && !isset($items[$item->url])) {
                $items[$item->url] = $item;
            }
        }

        return array_values($items);
    }

    /**
     * Ascends from the anchor to the card wrapper: up to a few hops while the
     * parent still contains exactly one eligible anchor, so sibling cards are
     * never swallowed.
     *
     * @param \SplObjectStorage<Element, int> $counts
     */
    private function container(Element $anchor, \SplObjectStorage $counts): Element
    {
        $container = $anchor;
        for ($hop = 0; $hop < self::MAX_CONTAINER_HOPS; $hop++) {
            $parent = $container->parentElement;
            if (
                $parent === null || $parent->tagName === 'BODY'
                || $this->eligibleAnchorCount($parent, $counts) !== 1
            ) {
                break;
            }
            $container = $parent;
        }

        return $container;
    }

    /** @param \SplObjectStorage<Element, int> $counts */
    private function eligibleAnchorCount(Element $element, \SplObjectStorage $counts): int
    {
        if ($counts->contains($element)) {
            return $counts[$element];
        }

        $count = 0;
        foreach ($element->querySelectorAll('a[href]') as $anchor) {
            if ($this->isEligible($anchor)) {
                $count++;
            }
        }
        $counts[$element] = $count;

        return $count;
    }

    /**
     * Primary score is item count; equal counts go to the group with the
     * longer average title, which favors headline cards over label lists.
     *
     * @param list<ScrapedItem> $candidate
     * @param list<ScrapedItem> $current
     */
    private function beats(array $candidate, array $current): bool
    {
        if (\count($candidate) !== \count($current)) {
            return \count($candidate) > \count($current);
        }

        return $this->meanTitleLength($candidate) > $this->meanTitleLength($current);
    }

    /** @param list<ScrapedItem> $items */
    private function meanTitleLength(array $items): float
    {
        if ($items === []) {
            return 0.0;
        }
        $total = 0;
        foreach ($items as $item) {
            $total += mb_strlen($item->title);
        }

        return $total / \count($items);
    }
}
