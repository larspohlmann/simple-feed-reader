<?php

declare(strict_types=1);

namespace App\Service\Scraper\Pass;

use App\Service\Fetch\Pass\PageUrls;
use App\Service\Html\Support\Srcset;
use App\Service\Parser\Support\DateParser;
use App\Service\Scraper\CardTitle;
use App\Service\Scraper\Model\ScrapedItemModel;
use App\Service\Scraper\Support\TextNormalizer;
use Dom\Element;

/** A ScrapedItemModel's fields from one card's container and anchor, resolved against the page the card is on. */
final readonly class CardFields
{
    public const int MIN_TITLE_LENGTH = 5;
    public const int MAX_TITLE_LENGTH = 300;
    public const int MIN_TEASER_LENGTH = 40;
    public const int MAX_TEASER_LENGTH = 1000;

    private const array NON_LEAF_CHILDREN = ['P', 'DIV', 'UL', 'OL', 'H1', 'H2', 'H3', 'H4', 'ARTICLE', 'SECTION'];

    public function __construct(private PageUrls $pageUrls, private CardTitle $cardTitle)
    {
    }

    public function item(Element $container, Element $anchor): ?ScrapedItemModel
    {
        $url = $this->pageUrls->httpUrl($anchor->getAttribute('href'));
        if ($url === null) {
            return null;
        }

        $title = $this->title($container, $anchor);
        if ($title === null) {
            return null;
        }

        return new ScrapedItemModel(
            url: $url,
            title: $title,
            teaser: self::teaser($container, $title),
            imageUrl: $this->image($container),
            publishedAt: self::publishedAt($container),
        );
    }

    private function title(Element $container, Element $anchor): ?string
    {
        $title = $this->cardTitle->of($container, $anchor);
        if ($title === null || mb_strlen($title) < self::MIN_TITLE_LENGTH) {
            return null;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH);
    }

    private static function teaser(Element $container, string $title): ?string
    {
        $teaser = null;
        foreach ($container->querySelectorAll('p, div, span') as $element) {
            if (!self::isLeafish($element)) {
                continue;
            }
            $text = TextNormalizer::normalize($element->textContent ?? '');
            if (mb_strlen($text) < self::MIN_TEASER_LENGTH || str_contains($text, $title)) {
                continue;
            }
            if ($teaser === null || mb_strlen($text) > mb_strlen($teaser)) {
                $teaser = $text;
            }
        }
        return $teaser ?? self::attributeTeaser($container);
    }

    private static function isLeafish(Element $element): bool
    {
        for ($child = $element->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if (\in_array($child->tagName, self::NON_LEAF_CHILDREN, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A description shipped in a data attribute, on the container or any descendant. Only `data-*` names qualify:
     * ARIA attributes such as aria-describedby hold element ids, not prose.
     */
    private static function attributeTeaser(Element $container): ?string
    {
        foreach ([$container, ...$container->querySelectorAll('*')] as $element) {
            foreach ($element->attributes as $attribute) {
                if (!str_starts_with($attribute->name, 'data-') || stripos($attribute->name, 'descri') === false) {
                    continue;
                }
                $value = TextNormalizer::normalize($attribute->value);
                if (mb_strlen($value) >= self::MIN_TEASER_LENGTH) {
                    return $value;
                }
            }
        }

        return null;
    }

    private function image(Element $container): ?string
    {
        $img = $container->querySelector('img');
        if (!$img instanceof Element) {
            return null;
        }
        $candidate = self::nonEmpty($img->getAttribute('src'))
            ?? self::nonEmpty($img->getAttribute('data-src'))
            ?? Srcset::firstUrl($img->getAttribute('srcset'));

        return $this->pageUrls->httpUrl($candidate);
    }

    private static function nonEmpty(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    private static function publishedAt(Element $container): ?\DateTimeImmutable
    {
        $time = $container->querySelector('time[datetime]');
        if (!$time instanceof Element) {
            return null;
        }

        return DateParser::parse($time->getAttribute('datetime'));
    }
}
