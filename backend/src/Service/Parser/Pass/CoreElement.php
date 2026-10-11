<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Model\FeedNamespace;
use App\Service\Parser\Support\XmlHelper;
use App\Service\Url\Support\AbsoluteHttpUrl;

/** A feed element whose children are read in exactly one namespace, so an extension never shadows a core child. */
final readonly class CoreElement
{
    public function __construct(public \DOMElement $element, private ?string $namespaceUri)
    {
    }

    /** RSS 2.0 core elements share their parent's namespace: none, or the document's default one. */
    public static function inOwnNamespace(\DOMElement $element): self
    {
        return new self($element, $element->namespaceURI);
    }

    public static function extension(\DOMElement $element, FeedNamespace $namespace): self
    {
        return new self($element, $namespace->value);
    }

    public function in(FeedNamespace $namespace): self
    {
        return self::extension($this->element, $namespace);
    }

    public function at(\DOMElement $element): self
    {
        return new self($element, $this->namespaceUri);
    }

    /** @phpstan-assert-if-true =\DOMElement $node */
    public function isCore(\DOMNode $node, string $localName): bool
    {
        return XmlHelper::isElement($node, $localName, $this->namespaceUri);
    }

    /** @phpstan-assert-if-true =\DOMElement $node */
    public function isEnclosure(\DOMNode $node): bool
    {
        return $this->isCore($node, 'enclosure')
            || ($this->isCore($node, 'link') && $node->getAttribute('rel') === 'enclosure');
    }

    public function text(string $localName): ?string
    {
        return self::firstText(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function httpUrl(string $localName): ?string
    {
        return self::firstHttpUrl(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function child(string $localName): ?\DOMElement
    {
        return self::firstElement(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    /** @return iterable<\DOMElement> */
    public function children(string $localName): iterable
    {
        return XmlHelper::childElements($this->element, $localName, $this->namespaceUri);
    }

    /**
     * Trimmed text of the first element that HAS text.
     *
     * @param iterable<\DOMElement> $elements
     */
    private static function firstText(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if ($text !== '') {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    private static function firstHttpUrl(iterable $elements): ?string
    {
        foreach ($elements as $element) {
            $text = trim($element->textContent);
            if (AbsoluteHttpUrl::matches($text)) {
                return $text;
            }
        }

        return null;
    }

    /** @param iterable<\DOMElement> $elements */
    private static function firstElement(iterable $elements): ?\DOMElement
    {
        foreach ($elements as $element) {
            return $element;
        }

        return null;
    }
}
