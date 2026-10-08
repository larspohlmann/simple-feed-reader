<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\FeedFormatParser\FeedFormatParserInterface;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

/**
 * One feed read as a stream: each entry is expanded into a DOM of its own and parsed on the spot, and the skeleton
 * keeps the rest, so memory follows the largest entry rather than the whole feed. The caller collects libxml errors.
 */
final class StreamedFeedDocument
{
    private const string XMLNS_NAMESPACE = 'http://www.w3.org/2000/xmlns/';
    private const array TEXT_TYPES = [\XMLReader::TEXT, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE];

    private readonly \DOMDocument $skeleton;
    private \DOMElement $root;
    private ?\DOMElement $openElement = null;

    /** @var list<ParsedEntryModel> */
    private array $entries = [];

    private function __construct(private readonly \XMLReader $reader)
    {
        $this->skeleton = new \DOMDocument();
    }

    public static function open(string $xml): self
    {
        $document = new self(\XMLReader::fromStream(self::spooled($xml), null, LIBXML_NONET | LIBXML_COMPACT));
        $document->readToRoot();

        return $document;
    }

    /** XMLReader::fromString() copies the whole body into libxml; a php://temp spool past 2 MB lives on disk. */
    /** @return resource */
    private static function spooled(string $xml): mixed
    {
        $spool = fopen('php://temp', 'w+b');
        if ($spool === false) {
            throw new \RuntimeException('Could not open a php://temp spool for the feed body');
        }
        fwrite($spool, $xml);
        rewind($spool);

        return $spool;
    }

    public function root(): \DOMElement
    {
        return $this->root;
    }

    public function parseWith(FeedFormatParserInterface $parser): ParsedFeedModel
    {
        $moved = $this->read();
        while ($moved) {
            $moved = $this->reader->nodeType === \XMLReader::ELEMENT
                ? $this->placeElement($parser)
                : $this->placeOtherNode();
        }

        return $parser->parseFeed($this->skeleton, $this->entries);
    }

    private function readToRoot(): void
    {
        while ($this->read()) {
            if ($this->reader->nodeType === \XMLReader::ELEMENT) {
                $this->root = $this->currentElement();
                $this->skeleton->appendChild($this->root);
                $this->openElement = $this->reader->isEmptyElement ? null : $this->root;

                return;
            }
        }

        throw new FeedParseException('Document is not well-formed XML');
    }

    private function placeElement(FeedFormatParserInterface $parser): bool
    {
        if ($this->openElement === null) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        $element = $this->currentElement();
        $this->openElement->appendChild($element);
        if ($parser->isEntry($element)) {
            $element->remove();
            $this->addEntry($parser->parseEntry($this->expandedEntry()));

            return $this->skipEntry();
        }

        if (!$this->reader->isEmptyElement) {
            $this->openElement = $element;
        }

        return $this->read();
    }

    private function placeOtherNode(): bool
    {
        $type = $this->reader->nodeType;
        if ($type === \XMLReader::END_ELEMENT) {
            $parent = $this->openElement?->parentNode;
            $this->openElement = $parent instanceof \DOMElement ? $parent : null;
        } elseif (in_array($type, self::TEXT_TYPES, true)) {
            $this->openElement?->appendChild($this->skeleton->createTextNode($this->reader->value));
        } elseif ($type === \XMLReader::CDATA) {
            $this->openElement?->appendChild($this->skeleton->createCDATASection($this->reader->value));
        }

        return $this->read();
    }

    private function currentElement(): \DOMElement
    {
        $namespace = $this->reader->namespaceURI;
        $element = $namespace === ''
            ? $this->skeleton->createElement($this->reader->name)
            : $this->skeleton->createElementNS($namespace, $this->reader->name);
        while ($this->reader->moveToNextAttribute()) {
            $this->copyAttribute($element);
        }
        $this->reader->moveToElement();

        return $element;
    }

    private function copyAttribute(\DOMElement $element): void
    {
        $namespace = $this->reader->namespaceURI;
        if ($namespace === self::XMLNS_NAMESPACE) {
            return;
        }
        if ($namespace === '') {
            $element->setAttribute($this->reader->name, $this->reader->value);

            return;
        }
        $element->setAttributeNS($namespace, $this->reader->name, $this->reader->value);
    }

    private function expandedEntry(): \DOMElement
    {
        // expand() raises a PHP warning of its own, outside libxml's collected errors; the false is what counts.
        $entry = @$this->reader->expand(new \DOMDocument());
        if (!$entry instanceof \DOMElement) {
            throw new FeedParseException('Document is not well-formed XML');
        }

        return $entry;
    }

    private function addEntry(?ParsedEntryModel $entry): void
    {
        if ($entry !== null) {
            $this->entries[] = $entry;
        }
    }

    private function read(): bool
    {
        return $this->checked($this->reader->read());
    }

    private function skipEntry(): bool
    {
        return $this->checked($this->reader->next());
    }

    /** A reader stops on a fatal error by reporting the end; a recoverable one (an undeclared prefix) reads on. */
    private function checked(bool $moved): bool
    {
        if (!$moved) {
            self::rejectFatalErrors();
        }
        // Feeds never need a DTD. Rejecting any doctype keeps a declared entity from ever being expanded, instead
        // of relying on libxml's amplification limit, which varies by version.
        if ($moved && $this->reader->nodeType === \XMLReader::DOC_TYPE) {
            throw new FeedParseException('Feed documents must not declare a DTD');
        }

        return $moved;
    }

    private static function rejectFatalErrors(): void
    {
        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                throw new FeedParseException('Document is not well-formed XML');
            }
        }
    }
}
