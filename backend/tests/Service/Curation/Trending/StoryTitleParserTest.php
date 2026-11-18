<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Service\Curation\Recommendation\Llm\Prompt\ModelReplyJsonDecoder;
use App\Service\Curation\Trending\StoryTitleParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StoryTitleParserTest extends TestCase
{
    /** @return iterable<string, array{string, ?string}> */
    public static function replies(): iterable
    {
        yield 'a trailing full stop is dropped' => [
            '{"title":"Hamburg port strike halts container traffic."}',
            'Hamburg port strike halts container traffic',
        ];
        yield 'a code fence is tolerated' => ["```json\n{\"title\":\"Streik im Hafen\"}\n```", 'Streik im Hafen'];
        yield 'a blank title' => ['{"title":"  "}', null];
        yield 'no title key' => ['{"headline":"x"}', null];
        yield 'not JSON' => ['not json', null];
        yield 'a title that is no string' => ['{"title":42}', null];
        yield 'a title over 120 characters' => ['{"title":"' . str_repeat('a', 121) . '"}', null];
        yield 'a title of 120 characters' => ['{"title":"' . str_repeat('ä', 120) . '"}', str_repeat('ä', 120)];
        yield 'German quotation marks are stripped' => ['{"title":"„Streik im Hafen“"}', 'Streik im Hafen'];
        yield 'a full stop after the quotes' => ['{"title":"„Streik im Hafen“."}', 'Streik im Hafen'];
        yield 'straight quotes are stripped' => ['{"title":"\"Streik im Hafen\""}', 'Streik im Hafen'];
        yield 'surrounding space is trimmed' => ['{"title":"  Streik im Hafen  "}', 'Streik im Hafen'];
        yield 'trailing marks are dropped together' => ['{"title":"Streik im Hafen?! …"}', 'Streik im Hafen'];
        yield 'only punctuation' => ['{"title":"…"}', null];
        yield 'an inner quote stays' => ['{"title":"Merz nennt Streik \"unnötig\""}', 'Merz nennt Streik "unnötig"'];
    }

    #[DataProvider('replies')]
    public function testParse(string $content, ?string $expected): void
    {
        self::assertSame($expected, (new StoryTitleParser(new ModelReplyJsonDecoder()))->parse($content));
    }
}
