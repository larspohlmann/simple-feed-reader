<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Service\Opml\Exception\InvalidOpmlException;

/**
 * The one hardened OPML parser (no network, no DTD, an <opml> root), shared by the OPML import and the catalog: a
 * security boundary, so never a second copy.
 */
final readonly class OpmlBodyReader
{
    public function read(string $opml): \DOMElement
    {
        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadXML($opml, \LIBXML_NONET | \LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;
        if (false === $loaded || null === $root || null !== $document->doctype || 'opml' !== $root->localName) {
            throw new InvalidOpmlException('Not a well-formed OPML 2.0 document.');
        }

        $body = $document->getElementsByTagName('body')->item(0);
        if (!$body instanceof \DOMElement) {
            throw new InvalidOpmlException('OPML has no <body>.');
        }

        return $body;
    }
}
