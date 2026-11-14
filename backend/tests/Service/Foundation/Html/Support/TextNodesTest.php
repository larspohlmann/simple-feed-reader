<?php

declare(strict_types=1);

namespace App\Tests\Service\Html\Support;

use App\Service\Html\Support\HtmlDocumentParser;
use App\Service\Html\Support\TextNodes;
use Dom\Text;
use PHPUnit\Framework\TestCase;

final class TextNodesTest extends TestCase
{
    public function testCollectsEveryTextNodeInDocumentOrder(): void
    {
        $document = HtmlDocumentParser::parseFragment('<p>one <a href="#">two <b>three</b></a> four</p><p>five</p>');

        self::assertSame(['one ', 'two ', 'three', ' four', 'five'], self::dataOf(TextNodes::in($document->body)));
    }

    public function testLeavesOutTheTextInsideSkippedElements(): void
    {
        $document = HtmlDocumentParser::parseFragment('<p>run <code>verbatim <b>too</b></code> on</p>');

        self::assertSame(['run ', ' on'], self::dataOf(TextNodes::in($document->body, ['code'])));
    }

    /**
     * @param list<Text> $texts
     *
     * @return list<string>
     */
    private static function dataOf(array $texts): array
    {
        return array_map(static fn (Text $text): string => $text->data, $texts);
    }
}
