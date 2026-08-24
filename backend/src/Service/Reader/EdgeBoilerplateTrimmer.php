<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use Dom\Element;

/**
 * Removes boilerplate blocks that survive readability at the head or tail of an
 * extracted article — related-post grids, newsletter prompts, comment blocks
 * (#582). It never touches the middle of the article: the same "related-links"
 * shape in the body is almost always a real subheading, so position is the
 * first gate.
 *
 * Runs on the readability output BEFORE EntrySanitizer, because the sanitizer
 * strips the class attributes and <form> elements this step reads as signals.
 *
 * Conservative: a block is removed only when two or more independent objective
 * signals agree (see shouldRemove); an ambiguous block, an undefined edge or an
 * unparsable body all leave the input unchanged.
 */
final readonly class EdgeBoilerplateTrimmer
{
    /** Characters of text that mark a block as a real, substantial paragraph. */
    private const int SUBSTANTIAL_PROSE_LENGTH = 200;

    /** The outer share of top-level blocks, at each end, that may count as edge. */
    private const float EDGE_FRACTION = 0.25;

    /** Whole class tokens that fingerprint an edge-boilerplate block. */
    private const array BOILERPLATE_CLASS_TOKENS = [
        // related-post grids
        'related', 'related-posts', 'related-articles', 'yarpp-related', 'jp-relatedposts',
        // newsletter / subscribe
        'newsletter', 'subscribe', 'mc4wp', 'mailchimp',
        // comments
        'comments', 'comments-area', 'comment-respond', 'comment-form', 'disqus',
    ];

    /** A block whose link text dominates its prose by at least this ratio is a link list. */
    private const float LINK_TEXT_RATIO = 0.6;

    /** A link list carries at least this many links. */
    private const int MIN_LINKS_FOR_LIST = 3;

    /**
     * Lowercase heading fragments that corroborate a boilerplate verdict, German
     * and English. Corroboration only: a phrase never removes a block on its own.
     */
    private const array PHRASE_FRAGMENTS = [
        // German
        'ähnliche beiträge', 'das könnte dich auch interessieren', 'mehr zum thema',
        'auch interessant', 'newsletter', 'jetzt anmelden', 'schreibe einen kommentar',
        'kommentar hinterlassen', 'kommentare',
        // English
        'related posts', 'related articles', 'you might also like', 'read more',
        'more from', 'sign up', 'subscribe', 'leave a comment', 'comments',
    ];

    public function trim(string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        if ($document === null || $document->body === null) {
            return $bodyHtml;
        }

        $blocks = $this->topLevelBlocks($this->contentRoot($document->body));
        $removedAny = $this->removeBoilerplateBlocks($blocks, $this->edgeIndexes($blocks));

        return $removedAny ? $document->saveHtml() : $bodyHtml;
    }

    /**
     * @param list<Element> $blocks
     * @param list<int> $edgeIndexes
     */
    private function removeBoilerplateBlocks(array $blocks, array $edgeIndexes): bool
    {
        $removedAny = false;
        foreach ($edgeIndexes as $index) {
            if ($this->shouldRemove($blocks[$index])) {
                $blocks[$index]->remove();
                $removedAny = true;
            }
        }

        return $removedAny;
    }

    /**
     * The element that directly holds the article's blocks. Readability wraps
     * its output in one container; descend through single-element container
     * wrappers so the block list is the real one, not a one-item list holding
     * the wrapper.
     */
    private function contentRoot(Element $body): Element
    {
        $root = $body;
        while (($onlyChild = $this->soleContainerChild($root)) !== null) {
            $root = $onlyChild;
        }

        return $root;
    }

    private function soleContainerChild(Element $element): ?Element
    {
        $onlyElement = null;
        foreach ($element->childNodes as $child) {
            if ($child instanceof Element) {
                if ($onlyElement !== null) {
                    return null;
                }
                $onlyElement = $child;
            } elseif (trim((string) $child->textContent) !== '') {
                return null;
            }
        }

        return $onlyElement !== null
            && in_array($onlyElement->localName, ['div', 'article', 'section', 'main'], true)
            ? $onlyElement
            : null;
    }

    /**
     * @return list<Element> the element children of the content root, in order
     */
    private function topLevelBlocks(Element $root): array
    {
        $blocks = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof Element) {
                $blocks[] = $child;
            }
        }

        return $blocks;
    }

    /**
     * The indexes of blocks that sit in the leading or trailing edge region.
     * Leading edge = blocks before the first substantial paragraph; trailing
     * edge = blocks after the last one; each capped at EDGE_FRACTION of the
     * block count so a short article cannot have its whole body classed as edge.
     * An article with no substantial paragraph has no defined edge.
     *
     * @param list<Element> $blocks
     * @return list<int>
     */
    private function edgeIndexes(array $blocks): array
    {
        $count = count($blocks);
        $substantial = $this->substantialIndexes($blocks);
        if ($substantial === []) {
            return [];
        }

        [$leadingEnd, $trailingStart] = $this->edgeBounds($count, $substantial);

        $leading = $leadingEnd > 0 ? range(0, $leadingEnd - 1) : [];
        $trailing = $trailingStart <= $count - 1 ? range($trailingStart, $count - 1) : [];

        return array_merge($leading, $trailing);
    }

    /**
     * The last index of the leading edge region and the first index of the
     * trailing edge region, both capped at EDGE_FRACTION of the block count.
     *
     * @param non-empty-list<int> $substantial
     * @return array{0: int, 1: int}
     */
    private function edgeBounds(int $count, array $substantial): array
    {
        $cap = (int) floor(self::EDGE_FRACTION * $count);
        $leadingEnd = min($substantial[0], $cap);
        $trailingStart = max($substantial[array_key_last($substantial)] + 1, $count - $cap);

        return [$leadingEnd, $trailingStart];
    }

    /**
     * @param list<Element> $blocks
     * @return list<int>
     */
    private function substantialIndexes(array $blocks): array
    {
        $indexes = [];
        foreach ($blocks as $index => $block) {
            if (mb_strlen(trim((string) $block->textContent)) >= self::SUBSTANTIAL_PROSE_LENGTH) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    /**
     * Two or more independent structural signals condemn a block; a single
     * structural signal condemns it only when a heading phrase corroborates.
     * A phrase on its own never removes anything.
     */
    private function shouldRemove(Element $block): bool
    {
        $structural = (int) $this->hasFingerprint($block)
            + (int) $this->isLinkList($block)
            + (int) $this->hasFormOrEmail($block);

        if ($structural >= 2) {
            return true;
        }

        return $structural >= 1 && $this->hasCorroboratingPhrase($block);
    }

    private function hasFingerprint(Element $block): bool
    {
        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/', trim($block->getAttribute('class') ?? '')) ?: [];

        return array_any(
            $tokens,
            static fn (string $token): bool => in_array($token, self::BOILERPLATE_CLASS_TOKENS, true),
        );
    }

    private function isLinkList(Element $block): bool
    {
        $links = $block->getElementsByTagName('a');
        if ($links->length < self::MIN_LINKS_FOR_LIST) {
            return false;
        }

        $blockTextLength = mb_strlen(trim((string) $block->textContent));
        if ($blockTextLength === 0) {
            return false;
        }

        $linkTextLength = 0;
        foreach ($links as $link) {
            $linkTextLength += mb_strlen(trim((string) $link->textContent));
        }

        return $linkTextLength / $blockTextLength >= self::LINK_TEXT_RATIO;
    }

    private function hasFormOrEmail(Element $block): bool
    {
        if ($block->getElementsByTagName('form')->length > 0) {
            return true;
        }

        foreach ($block->getElementsByTagName('input') as $input) {
            if (strtolower($input->getAttribute('type') ?? '') === 'email') {
                return true;
            }
        }

        return false;
    }

    private function hasCorroboratingPhrase(Element $block): bool
    {
        $heading = $block->querySelector('h1, h2, h3, h4');
        if ($heading === null) {
            return false;
        }

        $text = mb_strtolower(trim((string) $heading->textContent));
        if ($text === '') {
            return false;
        }

        return array_any(
            self::PHRASE_FRAGMENTS,
            static fn (string $fragment): bool => str_contains($text, $fragment),
        );
    }
}
