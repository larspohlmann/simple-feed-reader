<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Support\XmlHelper;

/** A feed element whose core children are read only in its dialect's namespace, so an extension never shadows them. */
final readonly class CoreElement
{
    public function __construct(public \DOMElement $element, private ?string $namespaceUri)
    {
    }

    public function at(\DOMElement $element): self
    {
        return new self($element, $this->namespaceUri);
    }

    public function text(string $localName): ?string
    {
        return XmlHelper::firstText(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function httpUrl(string $localName): ?string
    {
        return XmlHelper::firstHttpUrl(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    public function child(string $localName): ?\DOMElement
    {
        return XmlHelper::firstElement(XmlHelper::childElements($this->element, $localName, $this->namespaceUri));
    }

    /** @return iterable<\DOMElement> */
    public function children(string $localName): iterable
    {
        return XmlHelper::childElements($this->element, $localName, $this->namespaceUri);
    }
}
