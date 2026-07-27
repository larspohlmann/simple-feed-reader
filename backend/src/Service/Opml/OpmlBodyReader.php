<?php

declare(strict_types=1);

namespace App\Service\Opml;

use App\Exception\InvalidOpmlException;

/**
 * Turns untrusted OPML into a DOM, hardened the same way FeedParser hardens
 * feeds: no network, no DTD, and a root that must actually be <opml>.
 *
 * The single place OPML parsing lives: the catalog document uses it now, and the
 * user-facing OPML import will adopt it in a later step. Centralised on purpose —
 * this is a security boundary, and a second copy is a second thing to get wrong.
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
