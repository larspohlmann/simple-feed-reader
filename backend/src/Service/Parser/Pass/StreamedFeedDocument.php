<?php

declare(strict_types=1);

namespace App\Service\Parser\Pass;

use App\Service\Parser\Exception\FeedParseException;
use App\Service\Parser\Factory\FeedParserFactory;
use App\Service\Parser\FeedFormatParser\FeedFormatParserInterface;
use App\Service\Parser\Model\ParsedEntryModel;
use App\Service\Parser\Model\ParsedFeedModel;

/**
 * One feed read as a stream: each entry is expanded into a DOM of its own and parsed on the spot, and the skeleton
 * keeps the rest, so memory follows the largest entry rather than the whole feed.
 */
final class StreamedFeedDocument
{
    private const array TEXT_TYPES = [\XMLReader::TEXT, \XMLReader::WHITESPACE, \XMLReader::SIGNIFICANT_WHITESPACE];

    private readonly \DOMDocument $skeleton;
    private \DOMNode $openNode;

    /** @var list<ParsedEntryModel> */
    private array $entries = [];

    private function __construct(private readonly \XMLReader $reader)
    {
        $this->skeleton = new \DOMDocument();
        $this->openNode = $this->skeleton;
    }

    public static function parse(string $xml, FeedParserFactory $parserFactory): ParsedFeedModel
    {
        $previousErrorMode = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new self(\XMLReader::fromStream(self::spooled($xml), null, LIBXML_NONET));

            return $document->parseWith($parserFactory->parserFor($document->readToRoot()));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }
    }

    /**
     * XMLReader::fromString() copies the whole body into libxml; a php://temp spool past 2 MB lives on disk.
     *
     * @return resource
     */
    private static function spooled(string $xml): mixed
    {
        $spool = fopen('php://temp', 'w+b');
        if ($spool === false || fwrite($spool, $xml) !== strlen($xml)) {
            throw new FeedParseException('Could not spool the feed body for parsing');
        }
        rewind($spool);

        return $spool;
    }

    private function parseWith(FeedFormatParserInterface $parser): ParsedFeedModel
    {
        $moved = $this->read();
        while ($moved) {
            $moved = $this->reader->nodeType === \XMLReader::ELEMENT
                ? $this->placeElement($parser)
                : $this->placeOtherNode();
        }

        return $parser->parseFeed($this->skeleton, $this->entries);
    }

    private function readToRoot(): \DOMElement
    {
        while ($this->read()) {
            // Feeds never need a DTD. Rejecting any doctype keeps a declared entity from ever being expanded, instead
            // of relying on libxml's amplification limit, which varies by version.
            if ($this->reader->nodeType === \XMLReader::DOC_TYPE) {
                throw new FeedParseException('Feed documents must not declare a DTD');
            }
            if ($this->reader->nodeType === \XMLReader::ELEMENT) {
                $root = $this->currentElement();
                $this->descendInto($root);

                return $root;
            }
        }

        throw new FeedParseException('Document is not well-formed XML');
    }

    private function placeElement(FeedFormatParserInterface $parser): bool
    {
        $element = $this->currentElement();
        if ($parser->isEntry($element, $this->openNode)) {
            $this->addEntry($parser->parseEntry($this->expandedEntry()));

            return $this->skipEntry();
        }

        $this->descendInto($element);

        return $this->read();
    }

    /** Places the element under the open node, and descends into it unless it is empty. */
    private function descendInto(\DOMElement $element): void
    {
        $this->openNode->appendChild($element);
        if (!$this->reader->isEmptyElement) {
            $this->openNode = $element;
        }
    }

    private function placeOtherNode(): bool
    {
        $type = $this->reader->nodeType;
        if ($type === \XMLReader::END_ELEMENT) {
            $this->openNode = $this->openNode->parentNode ?? $this->skeleton;
        } elseif (in_array($type, self::TEXT_TYPES, true)) {
            $this->openNode->appendChild($this->skeleton->createTextNode($this->reader->value));
        } elseif ($type === \XMLReader::CDATA) {
            $this->openNode->appendChild($this->skeleton->createCDATASection($this->reader->value));
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
        return $this->unlessFatal($this->reader->read());
    }

    private function skipEntry(): bool
    {
        return $this->unlessFatal($this->reader->next());
    }

    /** A reader stops on a fatal error by reporting the end; a recoverable one (an undeclared prefix) reads on. */
    private function unlessFatal(bool $moved): bool
    {
        if (!$moved) {
            self::rejectFatalErrors();
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
