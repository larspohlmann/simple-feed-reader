<?php

declare(strict_types=1);

namespace App\Service\Ingest\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Ingest\Model\LemmyFeedPostModel;
use Dom\Element;
use Dom\Node;
use Dom\Text;

final class LemmyPostHeader
{
    private const string PROFILE_PATH = '#^/u/[^/]+/?$#';
    private const string COMMUNITY_PATH = '#^/c/[^/]+/?$#';
    private const string SCORE = '#^\s*-?\d+ points? \|\s*$#';
    private const int HEADER_LENGTH = 7;

    public static function read(string $html): ?LemmyFeedPostModel
    {
        $body = HtmlDocumentParser::parseFragment($html)->body;
        if ($body === null) {
            return null;
        }
        $nodes = iterator_to_array($body->childNodes, false);
        $profile = self::profileLink($nodes);
        if ($profile === null) {
            return null;
        }
        foreach (array_slice($nodes, 0, self::headerLength($nodes)) as $header) {
            $body->removeChild($header);
        }

        return new LemmyFeedPostModel(
            trim((string) $profile->textContent),
            (string) $profile->getAttribute('href'),
            $body->innerHTML,
        );
    }

    /** @param list<Node> $nodes */
    private static function profileLink(array $nodes): ?Element
    {
        $isHeader = count($nodes) >= self::HEADER_LENGTH
            && self::isText($nodes[0], '#^\s*submitted by\s*$#')
            && self::isLink($nodes[1], self::PROFILE_PATH)
            && self::isText($nodes[2], '#^\s*to\s*$#')
            && self::isLink($nodes[3], self::COMMUNITY_PATH)
            && self::isBreak($nodes[4])
            && self::isText($nodes[5], self::SCORE)
            && self::isLink($nodes[6], null);

        return $isHeader && $nodes[1] instanceof Element ? $nodes[1] : null;
    }

    /** @param list<Node> $nodes */
    private static function headerLength(array $nodes): int
    {
        $followedByBreak = isset($nodes[self::HEADER_LENGTH]) && self::isBreak($nodes[self::HEADER_LENGTH]);

        return self::HEADER_LENGTH + ($followedByBreak ? 1 : 0);
    }

    private static function isText(Node $node, string $pattern): bool
    {
        return $node instanceof Text && preg_match($pattern, $node->data) === 1;
    }

    private static function isBreak(Node $node): bool
    {
        return $node instanceof Element && $node->localName === 'br';
    }

    private static function isLink(Node $node, ?string $pathPattern): bool
    {
        if (!$node instanceof Element || $node->localName !== 'a') {
            return false;
        }

        return $pathPattern === null
            || preg_match($pathPattern, (string) parse_url((string) $node->getAttribute('href'), \PHP_URL_PATH)) === 1;
    }

    private function __construct()
    {
    }
}
