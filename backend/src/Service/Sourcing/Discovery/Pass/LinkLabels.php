<?php

declare(strict_types=1);

namespace App\Service\Discovery\Pass;

use App\Service\Html\Support\MetaProperty;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\Element;
use Dom\HTMLDocument;

/** The names of one page's feed links: a link's own label, or the page's name when that label is only a format word. */
final readonly class LinkLabels
{
    /** A label is a card heading, not an article: anything longer is markup that leaked in. */
    private const int MAX_LABEL_CHARS = 120;

    /** A label that names a format and nothing else: `RSS`, `Atom Feed`, `RSS 2.0`, `News feed`. */
    private const string GENERIC_LABEL = '#^(?:(?:rss|atom)\s*\d*(?:\.\d+)?(?:\s+feed)?|feed|news\s*feed)$#i';

    private ?string $pageName;

    public function __construct(HTMLDocument $document)
    {
        $this->pageName = $this->readPageName($document);
    }

    /** The link's own name: its title attribute, or the text a reader sees. */
    public function ownLabel(Element $link): ?string
    {
        foreach ([$link->getAttribute('title'), $link->getAttribute('aria-label'), $link->textContent] as $text) {
            $label = $this->normalized((string) $text);
            if (null !== $label) {
                return $label;
            }
        }

        return null;
    }

    public function nameFor(?string $ownLabel): ?string
    {
        if (null === $this->pageName || null === $ownLabel || 1 !== preg_match(self::GENERIC_LABEL, $ownLabel)) {
            return $ownLabel;
        }

        return $this->pageName;
    }

    private function readPageName(HTMLDocument $document): ?string
    {
        return $this->normalized(MetaProperty::content($document, 'og:title'))
            ?? $this->normalized((string) $document->title);
    }

    private function normalized(string $text): ?string
    {
        $normalized = TextNormalizer::normalize($text);

        return '' === $normalized ? null : mb_substr($normalized, 0, self::MAX_LABEL_CHARS);
    }
}
