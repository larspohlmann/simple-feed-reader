<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Image\DeclaredImage;
use Dom\Element;
use Dom\Node;
use Dom\Text;

/** Decides whether an article or feed picture may lead its rendered body. */
final class HeroImageSelector
{
    /** Standard layout whitespace, excluding U+00A0 which is visible text. */
    private const string LAYOUT_WHITESPACE = " \t\n\r\f\v\0";

    public function selectArticleHero(?DeclaredImage $candidate, string $bodyHtml): ?DeclaredImage
    {
        if (!$this->isHttpImage($candidate)) {
            return null;
        }

        $body = HtmlDocumentParser::parseOrNull($bodyHtml)?->body;
        if ($body === null || !$this->bodyLeadsWithImage($body)) {
            return $candidate;
        }

        return null;
    }

    public function selectFeedHero(?DeclaredImage $candidate, string $bodyHtml): ?DeclaredImage
    {
        if (!$this->isHttpImage($candidate)) {
            return null;
        }

        $body = HtmlDocumentParser::parseOrNull($bodyHtml)?->body;
        if ($body === null || !$this->bodyContainsImage($body)) {
            return $candidate;
        }

        return null;
    }

    private function isHttpImage(?DeclaredImage $candidate): bool
    {
        return $candidate !== null && preg_match('#^https?://#i', $candidate->url) === 1;
    }

    /** Whether the first rendered node in the body is an image. */
    private function bodyLeadsWithImage(Element $body): bool
    {
        foreach ($this->nodesInRenderOrder($body) as $node) {
            if ($node instanceof Element && $node->localName === 'img') {
                return true;
            }
            if ($node instanceof Text && $this->isVisibleText($node->data)) {
                return false;
            }
        }

        return false;
    }

    private function bodyContainsImage(Element $body): bool
    {
        foreach ($this->nodesInRenderOrder($body) as $node) {
            if ($node instanceof Element && $node->localName === 'img') {
                return true;
            }
        }

        return false;
    }

    /**
     * Every node under a root, depth-first, in document order.
     *
     * @return iterable<Node>
     */
    private function nodesInRenderOrder(Node $root): iterable
    {
        foreach ($root->childNodes as $child) {
            yield $child;
            yield from $this->nodesInRenderOrder($child);
        }
    }

    private function isVisibleText(string $text): bool
    {
        return trim($text, self::LAYOUT_WHITESPACE) !== '';
    }
}
